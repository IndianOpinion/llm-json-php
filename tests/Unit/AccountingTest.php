<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\CooldownStarted;
use IndianOpinion\LlmJson\CostSource;
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Event;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Guard\Repair;
use IndianOpinion\LlmJson\Guard\Violation;
use IndianOpinion\LlmJson\Log\Logger;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\PriceTable;
use IndianOpinion\LlmJson\RetryScheduled;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Skip;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;

/** PRD 4.12 and 4.13, tests T114 to T122. */
final class AccountingTest extends TestCase
{
    public function testT114BilledFailuresCountAndDetailsAreRead(): void
    {
        $bad = $this->ok(['title' => "x\u{200B}", 'paragraphs' => [], 'tags' => []], ['prompt_tokens' => 100, 'completion_tokens' => 40]);
        $this->transport->push($bad, $this->fixture('openai-success'));
        $result = $this->client(Route::to($this->openAi(), 'gpt-4o-mini'))->complete($this->request());
        self::assertSame(412, $result->usage()->promptTokens);
        self::assertSame(128, $result->usage()->cachedTokens);
        self::assertSame(0, $result->usage()->reasoningTokens);
        self::assertSame(512, $result->totalUsage()->promptTokens);
        self::assertSame(98, $result->totalUsage()->completionTokens);

        $this->transport->push($this->fixture('openrouter-success'));
        $result = $this->client()->complete($this->request());
        self::assertSame(12, $result->usage()->reasoningTokens);
    }

    public function testT115CostSources(): void
    {
        $prices = PriceTable::fromArray(['gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cached_input' => 0.075]]);
        $this->transport->push($this->fixture('openrouter-success'));
        $result = $this->client()->withPriceTable($prices->with('vendor/model-a', 100.0, 100.0))->complete($this->request());
        self::assertSame(0.0001205, $result->usage()->cost);
        self::assertSame(CostSource::Reported, $result->usage()->costSource);

        $this->transport->push($this->fixture('openai-success'));
        $result = $this->client(Route::to($this->openAi(), 'gpt-4o-mini'))->withPriceTable($prices)->complete($this->request());
        $expected = (284 * 0.15 + 128 * 0.075 + 58 * 0.60) / 1_000_000;
        self::assertEqualsWithDelta($expected, $result->usage()->cost, 1e-12);
        self::assertSame(CostSource::PriceTable, $result->usage()->costSource);

        $this->transport->push($this->fixture('openai-success'));
        $result = $this->client(Route::to($this->openAi(), 'unknown-model'))->withPriceTable($prices)->complete($this->request());
        self::assertNull($result->usage()->cost);
        self::assertSame(CostSource::Unknown, $result->usage()->costSource);
    }

    public function testT116ResultCarriesEverything(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $b = Route::to($this->openAi(), 'b');
        $c = Route::to($this->openRouter(), 'c');
        $this->store->set('llmjson.c.' . $a->id(), (int) $this->clock->now() + 600, 600);
        $this->transport->push(MockTransport::status(500, 'oops'), $this->ok(null, ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.5]), $this->ok());
        $this->clock->advance(0);
        $result = $this->client($a, $b, $c)->complete($this->request());

        self::assertSame($this->okData(), $result->data());
        self::assertSame('summary', $result->purpose());
        self::assertSame('openai', $result->provider());
        self::assertSame('b', $result->model());
        self::assertSame('test-model', $result->reportedModel());
        self::assertSame(OutputMode::Strict, $result->mode());
        self::assertNotSame('', $result->modeReason());
        self::assertCount(2, $result->attempts());
        $first = $result->attempts()[0];
        self::assertSame(1, $first->number);
        self::assertSame(500, $first->httpStatus);
        self::assertSame('oops', $first->errorExcerpt);
        self::assertIsInt($first->durationMs);
        self::assertSame('chatcmpl-test', $result->attempts()[1]->responseId);
        self::assertSame([Skip::COOLDOWN], array_map(static fn (Skip $s) => $s->reason, $result->skips()));
        self::assertSame(10, $result->usage()->promptTokens);
        self::assertSame(0.5, $result->totalUsage()->cost);
        self::assertNotEmpty($result->guardReport()->rulesRun);
        self::assertSame([], $result->exhaustedProviders());
        self::assertSame([], $result->storeErrors());
        self::assertSame([], $result->listenerErrors());
        self::assertGreaterThanOrEqual(0, $result->durationMs());
        self::assertNull($result->raw());
        self::assertTrue($result->usedFallback());
    }

    public function testT117AttemptEventAndThrowingListener(): void
    {
        $seen = [];
        $this->transport->push(MockTransport::status(500), $this->ok());
        $result = $this->client()
            ->on(Event::ATTEMPT_FINISHED, static function (object $a) use (&$seen): void {
                $seen[] = $a;
            })
            ->on(Event::ATTEMPT_FINISHED, static function (): void {
                throw new \RuntimeException('usage table is locked');
            })
            ->complete($this->request());
        self::assertCount(2, $seen);
        self::assertContainsOnlyInstancesOf(Attempt::class, $seen);
        self::assertSame($result->attempts(), $seen);
        self::assertCount(2, $result->listenerErrors());
        self::assertStringContainsString('usage table is locked', $result->listenerErrors()[0]);
        self::assertNotEmpty(array_filter($this->logger->records(Logger::WARNING), static fn ($r) => str_contains($r['message'], 'listener')));
    }

    public function testT118EachEventFires(): void
    {
        $log = [];
        $client = $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openRouter(), 'a2'), Route::to($this->openAi(), 'b'), Route::to($this->openAi(), 'c'))
            ->withGuardProfile('summary', GuardProfile::forSchema($this->article())->withSentenceEnd(['/title'], Action::Repair));
        foreach (Event::ALL as $event) {
            $client = $client->on($event, static function (object $payload) use (&$log, $event): void {
                $log[] = [$event, $payload];
            });
        }
        $this->transport->push(
            $this->fixture('openrouter-402'),
            MockTransport::status(500),
            $this->ok(['title' => "Bad\u{200B}.", 'paragraphs' => [], 'tags' => []]),
            $this->ok(['title' => 'Repaired title. Cut', 'paragraphs' => [], 'tags' => []]),
        );
        $client->complete($this->request());
        $names = array_map(static fn (array $e) => $e[0], $log);
        self::assertSame([
            Event::ATTEMPT_FINISHED,
            Event::CREDIT_EXHAUSTED,
            Event::ROUTE_SKIPPED,
            Event::ATTEMPT_FINISHED,
            Event::RETRY_SCHEDULED,
            Event::GUARD_REJECTED,
            Event::ATTEMPT_FINISHED,
            Event::ATTEMPT_FINISHED,
            Event::GUARD_REPAIRED,
        ], $names);
        $types = [];
        foreach ($log as [$name, $payload]) {
            $types[$name] = $payload::class;
        }
        self::assertSame(CreditExhaustion::class, $types[Event::CREDIT_EXHAUSTED]);
        self::assertSame(RetryScheduled::class, $types[Event::RETRY_SCHEDULED]);
        self::assertSame(Skip::class, $types[Event::ROUTE_SKIPPED]);
        self::assertSame(Violation::class, $types[Event::GUARD_REJECTED]);
        self::assertSame(Repair::class, $types[Event::GUARD_REPAIRED]);

        $log = [];
        $failing = $this->client(Route::to($this->openAi(), 'x'));
        foreach ([Event::COOLDOWN_STARTED, Event::CHAIN_EXHAUSTED] as $event) {
            $failing = $failing->on($event, static function (object $payload) use (&$log, $event): void {
                $log[] = [$event, $payload];
            });
        }
        $this->transport->push(MockTransport::status(404), MockTransport::status(404));
        foreach ([1, 2] as $_) {
            try {
                $failing->complete($this->request());
            } catch (ChainExhaustedException) {
            }
        }
        self::assertSame([Event::CHAIN_EXHAUSTED, Event::COOLDOWN_STARTED, Event::CHAIN_EXHAUSTED], array_map(static fn (array $e) => $e[0], $log));
        self::assertInstanceOf(CooldownStarted::class, $log[1][1]);
        self::assertInstanceOf(ChainExhaustedException::class, $log[0][1]);
    }

    public function testT119LogLevelsAndContext(): void
    {
        $this->transport->push(
            $this->fixture('openrouter-402'),
            MockTransport::status(500),
            $this->ok(),
        );
        $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        $levels = array_map(static fn ($r) => $r['level'], $this->logger->records());
        self::assertContains(Logger::DEBUG, $levels);
        self::assertContains(Logger::INFO, $levels);
        self::assertContains(Logger::WARNING, $levels);
        self::assertContains(Logger::CRITICAL, $levels);
        foreach ($this->logger->records(Logger::DEBUG) as $record) {
            foreach (['purpose', 'provider', 'model', 'attempt', 'outcome', 'status'] as $key) {
                self::assertArrayHasKey($key, $record['context']);
            }
        }
        $critical = $this->logger->records(Logger::CRITICAL)[0]['context'];
        self::assertSame('credit_exhausted', $critical['outcome']);
        self::assertSame(402, $critical['status']);

        $this->transport->push(MockTransport::status(404));
        try {
            $this->client(Route::to($this->openAi(), 'z'))->complete($this->request());
        } catch (ChainExhaustedException) {
        }
        self::assertCount(1, $this->logger->records(Logger::ERROR));
    }

    public function testT120CreditLogThrottled(): void
    {
        $events = 0;
        for ($i = 0; $i < 10; $i++) {
            $this->transport->push($this->fixture('openrouter-402'), $this->ok());
            $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))
                ->on(Event::CREDIT_EXHAUSTED, static function () use (&$events): void {
                    $events++;
                })
                ->complete($this->request());
            $this->clock->advance(61);
        }
        self::assertSame(10, $events);
        self::assertCount(1, $this->logger->records(Logger::CRITICAL));
        $this->clock->advance(600);
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertCount(2, $this->logger->records(Logger::CRITICAL));
    }

    public function testT121NoLoggerWritesNothing(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'llmjson-errlog');
        $previous = ini_set('error_log', (string) $log);
        ob_start();
        try {
            $this->transport->push($this->fixture('openrouter-402'), MockTransport::status(500), MockTransport::status(500));
            try {
                $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->withLogger(null)->complete($this->request());
            } catch (ChainExhaustedException) {
            }
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        self::assertSame('', $output);
        self::assertSame('', (string) file_get_contents((string) $log));
        unlink((string) $log);
        self::assertSame([], $this->logger->records());
    }

    public function testT122LogsHoldNoSecretsOrPrompt(): void
    {
        $content = 'NOT-JSON-OUTPUT ' . str_repeat('y', 400);
        $this->transport->push(MockTransport::completion($content), MockTransport::completion($content), MockTransport::status(401, 'bad key test-key-openai-one'));
        try {
            $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        } catch (ChainExhaustedException) {
        }
        $dump = $this->logger->dump();
        self::assertStringNotContainsString('test-key-openrouter-one', $dump);
        self::assertStringNotContainsString('test-key-openai-one', $dump);
        self::assertStringNotContainsString('Bearer', $dump);
        self::assertStringNotContainsString('PROMPT-TEXT-MARKER', $dump);
        self::assertStringNotContainsString('NOT-JSON-OUTPUT', $dump);

        $logger = new \IndianOpinion\LlmJson\Testing\RecordingLogger();
        $this->transport->push(MockTransport::completion($content), $this->ok());
        $this->client()->withLogger($logger)->withOptions(Options::defaults()->withExcerptLogging(true))->complete($this->request());
        $excerpts = array_values(array_filter(array_map(static fn ($r) => $r['context']['excerpt'] ?? null, $logger->records())));
        self::assertCount(1, $excerpts);
        self::assertStringStartsWith('NOT-JSON-OUTPUT', $excerpts[0]);
        self::assertSame(200, strlen($excerpts[0]));
        self::assertStringNotContainsString('PROMPT-TEXT-MARKER', $logger->dump());
    }

    public function testPsr3LoggerIsWrapped(): void
    {
        $psr = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message;
            }
        };
        $this->transport->push($this->ok());
        $this->client()->withLogger($psr)->complete($this->request());
        self::assertNotEmpty($psr->lines);
        self::assertStringStartsWith('debug', $psr->lines[0]);
        $this->expectException(\IndianOpinion\LlmJson\Exception\ConfigurationException::class);
        $this->client()->withLogger(new \stdClass());
    }
}
