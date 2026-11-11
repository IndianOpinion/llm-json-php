<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Event;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Internal\Backoff;
use IndianOpinion\LlmJson\Log\Logger;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Skip;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Transport\HttpRequest;
use IndianOpinion\LlmJson\Transport\HttpResponse;

/** PRD 4.9 and 4.10, tests T85 to T98. */
final class ChainTest extends TestCase
{
    public function testT85TwoAttemptsPerRouteRetryOnSameRoute(): void
    {
        $this->transport->push(MockTransport::status(500), MockTransport::status(500), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame([0, 0, 1], array_map(static fn ($a) => $a->routeIndex, $result->attempts()));
        self::assertCount(1, $this->sleeper->delays());

        $this->transport->push(MockTransport::status(500), MockTransport::status(500), MockTransport::status(500), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a')->withAttempts(4))->complete($this->request());
        self::assertCount(4, $result->attempts());
    }

    /** @return iterable<string, array{HttpResponse|\Throwable, bool}> */
    public static function outcomes(): iterable
    {
        yield 'transport' => [new TransportException('reset'), true];
        yield 'invalid response' => [MockTransport::status(200, '<html>'), true];
        yield 'provider error in 200' => [MockTransport::json(['error' => ['code' => 500, 'message' => 'x']]), true];
        yield 'truncated' => [MockTransport::completion('{"title":', 'length'), true];
        yield 'malformed' => [MockTransport::completion('{"title":'), true];
        yield 'schema violation' => [MockTransport::completion('{"title":"x"}'), true];
        yield 'guard rejected' => [MockTransport::completion(['title' => "x\u{200B}", 'paragraphs' => [], 'tags' => []]), true];
        yield '408' => [MockTransport::status(408), true];
        yield '425' => [MockTransport::status(425), true];
        yield '429' => [MockTransport::status(429), true];
        yield '500' => [MockTransport::status(500), true];
        yield '400' => [MockTransport::status(400), false];
        yield '401' => [MockTransport::status(401), false];
        yield '402' => [MockTransport::status(402), false];
        yield '403' => [MockTransport::status(403), false];
        yield '404' => [MockTransport::status(404), false];
        yield '409' => [MockTransport::status(409), false];
        yield '413' => [MockTransport::status(413), false];
        yield '422' => [MockTransport::status(422), false];
        yield 'credit 429' => [MockTransport::status(429, 'insufficient_quota'), false];
        yield 'refusal' => [MockTransport::json(['choices' => [['message' => ['content' => null, 'refusal' => 'no'], 'finish_reason' => 'stop']]]), false];
        yield 'content filter' => [MockTransport::completion('', 'content_filter'), false];
        yield 'redirect' => [MockTransport::status(302, '', ['Location' => 'https://elsewhere.example']), false];
        yield 'tls' => [new TransportException('certificate verify failed', tlsVerification: true), false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testT86RetryableAndNot(HttpResponse|\Throwable $first, bool $retried): void
    {
        $this->transport->push($first, $this->ok(), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
        self::assertSame($retried ? 0 : 1, $result->attempts()[1]->routeIndex);
    }

    public function testT87DelayRanges(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $d1 = Backoff::delay(1, 2.0, 30.0);
            $d2 = Backoff::delay(2, 2.0, 30.0);
            $d3 = Backoff::delay(3, 2.0, 30.0);
            $d5 = Backoff::delay(5, 2.0, 30.0);
            self::assertTrue($d1 >= 2.0 && $d1 <= 4.0, (string) $d1);
            self::assertTrue($d2 >= 4.0 && $d2 <= 8.0, (string) $d2);
            self::assertTrue($d3 >= 8.0 && $d3 <= 16.0, (string) $d3);
            self::assertTrue($d5 <= 30.0, (string) $d5);
            $r = Backoff::fromRetryAfter(5.0, 30.0);
            self::assertTrue($r >= 5.0 && $r <= 6.0);
            self::assertLessThanOrEqual(30.0, Backoff::fromRetryAfter(29.5, 30.0));
        }
    }

    public function testT88SleeperSeesEveryDelayWithoutRealTime(): void
    {
        $start = microtime(true);
        $this->transport->push(MockTransport::status(500), MockTransport::status(500), MockTransport::status(500), $this->ok());
        $this->client(Route::to($this->openAi(), 'a')->withAttempts(4))->complete($this->request());
        self::assertCount(3, $this->sleeper->delays());
        self::assertGreaterThanOrEqual(2.0 + 4.0 + 8.0, $this->sleeper->total());
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testT89SuccessOnSecondRouteEndsCall(): void
    {
        $this->transport->push(MockTransport::status(404), $this->ok(), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'), Route::to($this->openAi(), 'c'))->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertTrue($result->usedFallback());
        self::assertSame(Outcome::RequestRejected, $result->attempts()[0]->outcome);
        self::assertSame(1, $this->transport->remaining());
    }

    public function testT90CreditSkipsRestOfProvider(): void
    {
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $result = $this->client(
            Route::to($this->openRouter(), 'a1'),
            Route::to($this->openRouter(), 'a2'),
            Route::to($this->openRouter(), 'a3'),
            Route::to($this->openAi(), 'b'),
        )->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertSame(['a2', 'a3'], array_map(static fn (Skip $s) => $s->model, $result->skips()));
        self::assertSame([Skip::ACCOUNT, Skip::ACCOUNT], array_map(static fn (Skip $s) => $s->reason, $result->skips()));
    }

    public function testDifferentKeySameProviderIsNotSkipped(): void
    {
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $result = $this->client(
            Route::to($this->openRouter('test-key-openrouter-one'), 'a1'),
            Route::to($this->openRouter('test-key-openrouter-two'), 'a2'),
        )->complete($this->request());
        self::assertSame('a2', $result->model());
        self::assertSame([], $result->skips());
    }

    public function testT91CooledRouteIsSkipped(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $b = Route::to($this->openAi(), 'b');
        $this->store->set('llmjson.c.' . $a->id(), (int) $this->clock->now() + 600, 600);
        $this->transport->push($this->ok());
        $result = $this->client($a, $b)->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertSame(Skip::COOLDOWN, $result->skips()[0]->reason);
        self::assertSame(0, $result->skips()[0]->routeIndex);
    }

    public function testT92LastRouteAlwaysAttempted(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $b = Route::to($this->openAi(), 'b');
        foreach ([$a, $b] as $route) {
            $this->store->set('llmjson.c.' . $route->id(), (int) $this->clock->now() + 600, 600);
        }
        $this->transport->push($this->ok());
        $result = $this->client($a, $b)->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertTrue($result->attempts()[0]->lastRouteOverride);
        self::assertCount(1, $result->skips());

        $this->store->set('llmjson.h.' . $this->openRouter()->id(), (int) $this->clock->now() + 60, 60);
        $this->transport->push($this->ok());
        $result = $this->client(Route::to($this->openRouter(), 'x'), Route::to($this->openRouter(), 'y'))->complete($this->request());
        self::assertSame('y', $result->model());
        self::assertSame(Skip::HOLD, $result->skips()[0]->reason);
    }

    public function testT93TransportFailureTriesSiblingFirst(): void
    {
        $this->transport->push(new TransportException('reset'), new TransportException('reset'), $this->ok());
        $result = $this->client(
            Route::to($this->openRouter(), 'primary'),
            Route::to($this->openRouter(), 'sibling'),
            Route::to($this->openAi(), 'last-resort'),
        )->complete($this->request());
        self::assertSame('sibling', $result->model());
        self::assertSame([], $result->skips());
    }

    public function testT94ChainExhaustedVersusCreditExhausted(): void
    {
        $this->transport->push(MockTransport::status(404), MockTransport::status(404));
        try {
            $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
            self::fail('expected exception');
        } catch (CreditExhaustedException) {
            self::fail('no credit problem here');
        } catch (ChainExhaustedException $e) {
            self::assertSame(ChainExhaustedException::REASON_FAILURES, $e->reason());
            self::assertCount(2, $e->attempts());
        }

        $this->transport->push($this->fixture('openai-429-quota'), MockTransport::status(404));
        try {
            $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertInstanceOf(CreditExhaustedException::class, $e);
            self::assertSame('openai', $e->exhaustedProviders()[0]->provider);
            self::assertNull($e->result());
        }
    }

    public function testT95CreditThenSuccessReturnsResultOrThrowsInStrictMode(): void
    {
        $events = [];
        $client = $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))
            ->on(Event::CREDIT_EXHAUSTED, static function (object $e) use (&$events): void {
                $events[] = $e;
            });
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $result = $client->complete($this->request());
        self::assertTrue($result->hadCreditExhaustion());
        self::assertSame('openrouter', $result->exhaustedProviders()[0]->provider);
        self::assertCount(1, $events);
        self::assertCount(1, $this->logger->records(Logger::CRITICAL));

        $this->clock->advance(61);
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        try {
            $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request()->withStrictCredit());
            self::fail('expected exception');
        } catch (CreditExhaustedException $e) {
            self::assertNotNull($e->result());
            self::assertSame($this->okData(), $e->result()->data());
        }

        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $this->expectException(CreditExhaustedException::class);
        $this->client(Route::to($this->openRouter('test-key-openrouter-two'), 'a'), Route::to($this->openAi(), 'b'))
            ->withOptions(Options::defaults()->withStrictCredit(true))
            ->complete($this->request());
    }

    public function testT96FullFailureIncrementsDayCounter(): void
    {
        $this->transport->push(MockTransport::status(404), MockTransport::status(404));
        $client = $this->client(Route::to($this->openAi(), 'a'));
        foreach ([1, 2] as $_) {
            try {
                $client->complete($this->request());
            } catch (ChainExhaustedException) {
            }
        }
        self::assertSame(2, $client->status()->chainFailuresToday);
        self::assertSame(2, $this->store->get('llmjson.n.' . gmdate('Ymd', (int) $this->clock->now())));
    }

    public function testT97PerCallChainOverride(): void
    {
        $client = $this->client(Route::to($this->openAi(), 'default'));
        $this->transport->push($this->ok(), $this->ok());
        self::assertSame('override', $client->complete($this->request()->withChain(Chain::of(Route::to($this->openRouter(), 'override'))))->model());
        self::assertSame('default', $client->complete($this->request())->model());
    }

    public function testT98Deadline(): void
    {
        $clock = $this->clock;
        $timeouts = [];
        $slow = static function (HttpRequest $r) use ($clock, &$timeouts): HttpResponse {
            $timeouts[] = $r->timeout;
            $clock->advance(8.0);

            return MockTransport::status(500);
        };
        $this->transport->push($slow, $slow, $slow, $slow);
        try {
            $this->client(Route::to($this->openAi(), 'a')->withAttempts(5))->complete($this->request()->withDeadline(20.0));
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertTrue($e->isDeadline());
            self::assertSame(ChainExhaustedException::REASON_DEADLINE, $e->reason());
            self::assertNotEmpty($e->attempts());
            self::assertStringContainsString('Deadline', $e->getMessage());
        }
        self::assertSame(20.0, $timeouts[0], 'request timeout clipped to the time left');
        self::assertLessThan(20.0, $timeouts[1] ?? 0.0);
        self::assertLessThanOrEqual(2, count($timeouts));
    }
}
