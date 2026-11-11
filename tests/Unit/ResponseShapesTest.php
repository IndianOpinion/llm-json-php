<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\MalformedOutputException;
use IndianOpinion\LlmJson\Exception\RefusalException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Internal\ResponseReader;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Transport\HttpResponse;

/** PRD 4.5, tests T30 to T50. */
final class ResponseShapesTest extends TestCase
{
    /** @return list<Outcome> */
    private function outcomesFor(HttpResponse ...$responses): array
    {
        $this->transport->push(...$responses);
        try {
            $result = $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());

            return array_map(static fn ($a) => $a->outcome, $result->attempts());
        } catch (ChainExhaustedException $e) {
            return array_map(static fn ($a) => $a->outcome, $e->attempts());
        }
    }

    public function testT30GoodOpenAiAndOpenRouterShapes(): void
    {
        $this->transport->push($this->fixture('openai-success'), $this->fixture('openrouter-success'));
        $result = $this->client(Route::to($this->openAi(), 'gpt-4o-mini'))->complete($this->request());
        self::assertSame('Monsoon arrives early in Kerala', $result->data()['title']);
        $result = $this->client(Route::to($this->openRouter(), 'vendor/model-a'))->complete($this->request());
        self::assertSame(['weather'], $result->data()['tags']);
    }

    public function testT31FenceUnwrappedOnceAndRecorded(): void
    {
        $json = (string) json_encode($this->okData());
        foreach (["```json\n" . $json . "\n```", "```\n" . $json . "\n```"] as $content) {
            $this->transport->push(MockTransport::completion($content));
            $result = $this->client()->complete($this->request());
            self::assertSame($this->okData(), $result->data());
            self::assertTrue($result->fenceUnwrapped());
            self::assertTrue($result->attempts()[0]->fenceUnwrapped);
        }
        $double = "```json\n```json\n" . $json . "\n```\n```";
        self::assertSame([Outcome::Malformed, Outcome::Malformed], $this->outcomesFor(MockTransport::completion($double), MockTransport::completion($double)));
    }

    public function testT32WhitespaceAroundBodyAndContent(): void
    {
        $this->transport->push($this->fixture('openrouter-success'));
        self::assertSame('Monsoon arrives early in Kerala', $this->client()->complete($this->request())->data()['title']);
        $this->transport->push(MockTransport::completion("\n\n  " . json_encode($this->okData()) . "  \n"));
        self::assertSame($this->okData(), $this->client()->complete($this->request())->data());
    }

    public function testT33ContentPartsAreJoined(): void
    {
        $json = (string) json_encode($this->okData());
        $half = intdiv(strlen($json), 2);
        $this->transport->push(MockTransport::json([
            'choices' => [['message' => ['content' => [
                ['type' => 'text', 'text' => substr($json, 0, $half)],
                ['type' => 'image_url', 'image_url' => ['url' => 'x']],
                ['type' => 'text', 'text' => substr($json, $half)],
            ]], 'finish_reason' => 'stop']],
        ]));
        self::assertSame($this->okData(), $this->client()->complete($this->request())->data());
    }

    public function testT34RefusalMovesOnAndAllRefusedCarriesFlag(): void
    {
        $this->transport->push($this->fixture('openai-refusal'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame([Outcome::Refusal, Outcome::Success], array_map(static fn ($a) => $a->outcome, $result->attempts()));
        $error = $result->attempts()[0]->error;
        self::assertInstanceOf(RefusalException::class, $error);
        self::assertStringContainsString("can't help", $error->refusal());

        $long = MockTransport::json(['choices' => [['message' => ['content' => null, 'refusal' => str_repeat('no ', 400)], 'finish_reason' => 'stop']]]);
        $this->transport->push($long, $this->fixture('openai-refusal'));
        try {
            $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertTrue($e->allRefused());
            self::assertSame(ChainExhaustedException::REASON_ALL_REFUSED, $e->reason());
            self::assertInstanceOf(RefusalException::class, $e->lastError());
            $first = $e->attempts()[0]->error;
            self::assertInstanceOf(RefusalException::class, $first);
            self::assertSame(500, strlen($first->refusal()));
        }
    }

    public function testT35ToolCallsAreInvalidAndRetried(): void
    {
        self::assertSame([Outcome::InvalidResponse, Outcome::Success], $this->outcomesFor($this->fixture('openai-tool-calls'), $this->ok()));
    }

    public function testT36FinishReasonLengthIsRejectedEvenIfParseable(): void
    {
        self::assertSame([Outcome::Truncated, Outcome::Success], $this->outcomesFor($this->fixture('openai-length'), $this->ok()));
    }

    public function testT37ContentFilterMovesOnWithoutRetry(): void
    {
        $filtered = MockTransport::completion('', 'content_filter');
        $this->transport->push($filtered, $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame(Outcome::ContentFilter, $result->attempts()[0]->outcome);
        self::assertSame(1, $result->attempts()[1]->routeIndex);
    }

    public function testT38EmptyChoicesRetried(): void
    {
        self::assertSame([Outcome::InvalidResponse, Outcome::Success], $this->outcomesFor(MockTransport::json(['id' => 'x', 'choices' => []]), $this->ok()));
        self::assertSame([Outcome::InvalidResponse, Outcome::Success], $this->outcomesFor(MockTransport::json(['id' => 'x']), $this->ok()));
    }

    public function testT39ErrorObjectInsideA200(): void
    {
        self::assertSame([Outcome::ProviderError, Outcome::Success], $this->outcomesFor($this->fixture('openrouter-error-in-200'), $this->ok()));

        $this->transport->push(MockTransport::json(['error' => ['code' => 402, 'message' => 'Insufficient balance']]));
        try {
            $this->client(Route::to($this->openRouter(), 'a'))->complete($this->request());
            self::fail('expected exception');
        } catch (CreditExhaustedException $e) {
            self::assertSame(Outcome::CreditExhausted, $e->attempts()[0]->outcome);
            self::assertCount(1, $e->attempts(), 'credit is never retried');
        }
    }

    public function testT40ErrorInsideFirstChoice(): void
    {
        self::assertSame([Outcome::ProviderError, Outcome::Success], $this->outcomesFor($this->fixture('openrouter-choice-error'), $this->ok()));
        $this->transport->push(MockTransport::json(['choices' => [['error' => ['code' => 402, 'message' => 'no credit']]]]));
        $this->expectException(CreditExhaustedException::class);
        $this->client(Route::to($this->openRouter(), 'a'))->complete($this->request());
    }

    public function testT41EmptyHtmlAndInvalidUtf8BodiesRetried(): void
    {
        foreach ([MockTransport::status(200, ''), $this->fixture('proxy-html'), MockTransport::status(200, "{\"choices\":\"\xC3\x28\"}")] as $bad) {
            self::assertSame([Outcome::InvalidResponse, Outcome::Success], $this->outcomesFor($bad, $this->ok()));
        }
    }

    public function testT42NonJsonContentRetriedWithExcerpt(): void
    {
        $text = 'Sure! Here is the summary you asked for: ' . str_repeat('lorem ipsum ', 50);
        $this->transport->push(MockTransport::completion($text), MockTransport::completion($text));
        try {
            $this->client()->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertSame([Outcome::Malformed, Outcome::Malformed], array_map(static fn ($a) => $a->outcome, $e->attempts()));
            $error = $e->attempts()[0]->error;
            self::assertInstanceOf(MalformedOutputException::class, $error);
            self::assertSame(200, strlen($error->excerpt()));
            self::assertStringNotContainsString('lorem', $error->getMessage());
        }
    }

    public function testT43ArrayContentIsSchemaViolation(): void
    {
        self::assertSame([Outcome::SchemaViolation, Outcome::Success], $this->outcomesFor(MockTransport::completion('[1,2,3]'), $this->ok()));
        self::assertSame([Outcome::SchemaViolation, Outcome::Success], $this->outcomesFor(MockTransport::completion('"just a string"'), $this->ok()));
    }

    public function testT44DuplicateKeysLastWins(): void
    {
        $this->transport->push(MockTransport::completion('{"title":"First","title":"Second","paragraphs":[],"tags":[]}'));
        self::assertSame('Second', $this->client()->complete($this->request())->data()['title']);
    }

    public function testT45IntegerBeyondRangeFailsIntegerField(): void
    {
        $schema = Schema::fromArray('count', ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n'], 'additionalProperties' => false]);
        $this->transport->push(MockTransport::completion('{"n":99999999999999999999}'), MockTransport::completion('{"n":42}'));
        $result = $this->client()->complete($this->request($schema));
        $error = $result->attempts()[0]->error;
        self::assertInstanceOf(SchemaViolationException::class, $error);
        self::assertSame('/n', $error->path());
        self::assertSame(42, $result->data()['n']);
    }

    public function testT46NestingLimit(): void
    {
        $schema = Schema::fromArray('deep', ['type' => 'object', 'properties' => ['a' => []], 'required' => ['a'], 'additionalProperties' => false]);
        $nest = static fn (int $levels): string => '{"a":' . str_repeat('[', $levels - 1) . '1' . str_repeat(']', $levels - 1) . '}';
        $this->transport->push(MockTransport::completion($nest(64)));
        $this->client()->complete($this->request($schema));
        $this->transport->push(MockTransport::completion($nest(65)), MockTransport::completion($nest(65)));
        try {
            $this->client()->complete($this->request($schema));
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertSame(Outcome::Malformed, $e->attempts()[0]->outcome);
        }
    }

    public function testT47MissingUsageIsNullNotZero(): void
    {
        $this->transport->push($this->ok(null, null));
        $result = $this->client()->complete($this->request());
        self::assertNull($result->usage()->promptTokens);
        self::assertNull($result->usage()->completionTokens);
        self::assertNull($result->usage()->totalTokens());
        self::assertNull($result->usage()->cost);
        self::assertNull($result->totalUsage()->promptTokens);
        $this->transport->push($this->ok(null, ['prompt_tokens' => 10]));
        $result = $this->client()->complete($this->request());
        self::assertSame(10, $result->usage()->promptTokens);
        self::assertNull($result->usage()->completionTokens);
    }

    public function testT48ReportedModelRecordedRequestedModelKeysCooldown(): void
    {
        $this->transport->push(MockTransport::completion($this->okData(), model: 'vendor/model-a-20250101'));
        $route = Route::to($this->openRouter(), 'vendor/model-a');
        $result = $this->client($route)->complete($this->request());
        self::assertSame('vendor/model-a', $result->model());
        self::assertSame('vendor/model-a-20250101', $result->reportedModel());
        self::assertSame('vendor/model-a-20250101', $result->attempts()[0]->reportedModel);

        $this->transport->push(MockTransport::status(500), MockTransport::status(500));
        try {
            $this->client($route)->complete($this->request());
        } catch (ChainExhaustedException) {
        }
        self::assertSame(1, $this->client($route)->status()->routes[0]->failureCount);
        $other = Route::to($this->openRouter(), 'vendor/model-a-20250101');
        self::assertSame(0, $this->client($other)->status()->routes[0]->failureCount);
    }

    public function testT49ResponseAndRequestIds(): void
    {
        $this->transport->push($this->fixture('openai-success'));
        $attempt = $this->client(Route::to($this->openAi(), 'gpt-4o-mini'))->complete($this->request())->attempts()[0];
        self::assertSame('chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT', $attempt->responseId);
        self::assertSame('req_8f2c1a7d', $attempt->requestId);
    }

    public function testT50RetryAfterSecondsAndHttpDate(): void
    {
        $reader = new ResponseReader();
        $now = $this->clock->now();
        self::assertSame(7.0, $reader->retryAfter('7', $now));
        $date = gmdate('D, d M Y H:i:s', (int) $now + 12) . ' GMT';
        self::assertSame(12.0, $reader->retryAfter($date, $now));
        self::assertNull($reader->retryAfter('soon', $now));

        $this->transport->push(MockTransport::status(429, '{"error":{"message":"slow down"}}', ['Retry-After' => '7']), $this->ok());
        $this->client()->complete($this->request());
        $delay = $this->sleeper->delays()[0];
        self::assertGreaterThanOrEqual(7.0, $delay);
        self::assertLessThanOrEqual(8.0, $delay);

        $this->clock->set(1_767_300_000.0);
        $this->transport->push(MockTransport::status(503, 'busy', ['Retry-After' => gmdate('D, d M Y H:i:s', (int) $this->clock->now() + 4) . ' GMT']), $this->ok());
        $this->client()->complete($this->request());
        $delay = $this->sleeper->delays()[1];
        self::assertGreaterThanOrEqual(4.0, $delay);
        self::assertLessThanOrEqual(5.0, $delay);
    }

    public function testChainOfOneWorksViaRequestOverride(): void
    {
        $this->transport->push($this->ok());
        $result = $this->client()->complete($this->request()->withChain(Chain::of(Route::to($this->openAi(), 'override-model'))));
        self::assertSame('override-model', $result->model());
    }
}
