<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\CostSource;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\GuardRejectedException;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Guard\TextRules;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Transport\HttpResponse;

/**
 * Regression tests for issues found in review: each test names the case it
 * protects.
 */
final class ReviewFixesTest extends TestCase
{
    private function schemaWithMinimums(): Schema
    {
        return Schema::fromArray('story', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
            ],
            'required' => ['title', 'paragraphs'],
            'additionalProperties' => false,
        ]);
    }

    /** A repair may never hand back data the schema rejects (case 73 with 61). */
    public function testRepairThatBreaksTheSchemaIsARejection(): void
    {
        $schema = $this->schemaWithMinimums();
        $profile = GuardProfile::forSchema($schema)->withSentenceEnd(['/title', '/paragraphs'], Action::Repair);
        $broken = ['title' => 'A headline without an end', 'paragraphs' => ['and a fragment']];
        $fine = ['title' => 'A headline.', 'paragraphs' => ['A whole sentence.']];
        $this->transport->push(MockTransport::completion($broken), MockTransport::completion($fine));
        $result = $this->client()->withGuardProfile('summary', $profile)->complete($this->request($schema));
        self::assertSame($fine, $result->data());
        self::assertSame(Outcome::GuardRejected, $result->attempts()[0]->outcome);
        $error = $result->attempts()[0]->error;
        self::assertInstanceOf(GuardRejectedException::class, $error);
        self::assertStringContainsString('no longer matches the schema', $error->getMessage());
    }

    /** Case 73: a terminator followed by a lower-case word is not a sentence end. */
    public function testRepairDoesNotCutAtAQuotedFullStopInsideASentence(): void
    {
        $abbr = GuardProfile::ENGLISH_ABBREVIATIONS;
        self::assertSame('First sentence.', TextRules::cutToLastSentence('First sentence. He said “Stop.” and then', $abbr));
        self::assertSame('', TextRules::cutToLastSentence('He said “Stop.” and then left without', $abbr));
        self::assertSame('Dr. Rao met the U.S. envoy.', TextRules::cutToLastSentence('Dr. Rao met the U.S. envoy. And then', $abbr));
        self::assertSame('पहला वाक्य।', TextRules::cutToLastSentence('पहला वाक्य। दूसरा', $abbr));
    }

    /** Case 39: OpenRouter's finish reason "error" is a provider error, retried. */
    public function testFinishReasonErrorIsRetried(): void
    {
        $this->transport->push(MockTransport::completion($this->okData(), 'error'), $this->ok());
        $result = $this->client()->complete($this->request());
        self::assertSame(Outcome::ProviderError, $result->attempts()[0]->outcome);
        self::assertCount(2, $result->attempts());
    }

    /** Case 39: an error code that is not a scalar raises no PHP warning. */
    public function testNonScalarErrorCodeIsAProviderError(): void
    {
        $body = new HttpResponse(200, [], '{"error":{"code":["x"],"message":"upstream failed"}}');
        $this->transport->push($body, $this->ok());
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $result = $this->client()->complete($this->request());
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings);
        self::assertSame(Outcome::ProviderError, $result->attempts()[0]->outcome);
    }

    /** Case 21: stream and n may not be set through extra body fields. */
    public function testExtraBodyCannotTurnOnStreaming(): void
    {
        foreach (['stream' => true, 'n' => 2] as $field => $value) {
            try {
                Route::to($this->openRouter(), 'm')->withExtraBody([$field => $value]);
                self::fail('accepted ' . $field);
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    /** Case 79: a custom rule that returns something other than a list is a caller bug. */
    public function testCustomRuleReturningANonIterableIsAConfigurationError(): void
    {
        $profile = GuardProfile::default()->withCustomRule('broken', static fn (array $data): mixed => true);
        $this->transport->push($this->ok());
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('broken');
        $this->client()->withGuardProfile('summary', $profile)->complete($this->request());
    }

    /** Case 109: set_transient() returns false when the value is unchanged; that is not a failure. */
    public function testWordPressStoreAcceptsAnUnchangedValue(): void
    {
        $data = [];
        $store = new WordPressTransientStore(
            static function (string $k) use (&$data): mixed {
                return $data[$k] ?? false;
            },
            static function (string $k, mixed $v, int $ttl) use (&$data): bool {
                $changed = ($data[$k] ?? null) !== $v;
                $data[$k] = $v;

                return $changed;
            },
            static function (string $k) use (&$data): bool {
                unset($data[$k]);

                return true;
            },
            false,
        );
        $store->set('llmjson.h.x', 5, 60);
        $store->set('llmjson.h.x', 5, 60);
        self::assertSame(5, $store->get('llmjson.h.x'));
    }

    /** Case 97: a retry whose wait would break the deadline gives way to the next route. */
    public function testDeadlineSkipsTheRetryWaitAndTriesTheNextRoute(): void
    {
        $this->transport->push(MockTransport::status(429, '', ['retry-after' => '20']), $this->ok());
        $client = $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))
            ->withOptions(Options::defaults()->withDeadline(22.0, 5.0));
        $result = $client->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertSame([], $this->sleeper->delays());
    }

    /** Case 115: a billed attempt with an unknown cost makes the total a lower bound. */
    public function testTotalCostIsPartialWhenABilledAttemptHasNoCost(): void
    {
        $withCost = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.5];
        $this->transport->push(MockTransport::completion('{', usage: null), MockTransport::completion($this->okData(), usage: $withCost));
        $result = $this->client()->complete($this->request());
        self::assertSame(0.5, $result->totalUsage()->cost);
        self::assertSame(CostSource::Partial, $result->totalUsage()->costSource);
        self::assertSame(CostSource::Reported, $result->usage()->costSource);
    }

    /** Case 122: a custom transport's exception message never carries the key. */
    public function testForeignTransportExceptionMessageIsRedacted(): void
    {
        $key = 'sk-or-v1-0123456789abcdefghijklmnop';
        $this->transport->push(new \RuntimeException('auth header was Bearer ' . $key), new \RuntimeException('key ' . $key));
        try {
            $this->client(Route::to($this->openRouter($key), 'a'))->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertStringNotContainsString($key, $e->getMessage());
            foreach ($e->attempts() as $attempt) {
                self::assertStringNotContainsString($key, (string) $attempt->error?->getMessage());
            }
        }
        foreach ($this->logger->records() as $record) {
            self::assertStringNotContainsString($key, json_encode($record) ?: '');
        }
    }
}
