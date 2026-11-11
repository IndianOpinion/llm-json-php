<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\CooldownStarted;
use IndianOpinion\LlmJson\Event;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Log\Logger;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Skip;
use IndianOpinion\LlmJson\Store\MemoryStore;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\Support\CountingStore;
use IndianOpinion\LlmJson\Tests\TestCase;

/** PRD 4.11, tests T99 to T104 and T110 to T113. */
final class CooldownTest extends TestCase
{
    private function failOnce(Route ...$routes): void
    {
        try {
            $this->client(...$routes)->complete($this->request());
            self::fail('expected the chain to fail');
        } catch (ChainExhaustedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testT99CreditHold(): void
    {
        $a = Route::to($this->openRouter(), 'a');
        $b = Route::to($this->openAi(), 'b');
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $this->client($a, $b)->complete($this->request());
        self::assertNotNull($this->client($a)->status()->routes[0]->holdUntil);

        $this->clock->advance(30);
        $this->transport->push($this->ok());
        $result = $this->client($a, $b)->complete($this->request());
        self::assertSame(Skip::HOLD, $result->skips()[0]->reason);
        self::assertSame('b', $result->model());

        $this->clock->advance(31);
        $this->transport->push($this->ok());
        $result = $this->client($a, $b)->complete($this->request());
        self::assertSame('a', $result->model());

        $this->transport->push($this->fixture('openrouter-402'), $this->ok(), $this->ok());
        $client = $this->client($a, $b)->withOptions(Options::defaults()->withCreditHold(0));
        $client->complete($this->request());
        self::assertNull($client->status()->routes[0]->holdUntil);
        self::assertSame('a', $client->complete($this->request())->model());
    }

    public function testT100FailureCounterAndWindow(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $this->transport->push(MockTransport::status(404));
        $this->failOnce($a);
        self::assertSame(1, $this->client($a)->status()->routes[0]->failureCount);
        $this->clock->advance(1801);
        self::assertSame(0, $this->client($a)->status()->routes[0]->failureCount);
    }

    public function testT101CooldownStartsOnceAndIsLoggedOnce(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $b = Route::to($this->openAi(), 'b');
        $events = [];
        $listen = static function (object $e) use (&$events): void {
            $events[] = $e;
        };
        $this->transport->push(MockTransport::status(404), $this->ok(), MockTransport::status(404), $this->ok());
        $this->client($a, $b)->on(Event::COOLDOWN_STARTED, $listen)->complete($this->request());
        $this->client($a, $b)->on(Event::COOLDOWN_STARTED, $listen)->complete($this->request());
        $status = $this->client($a)->status()->routes[0];
        self::assertSame(0, $status->failureCount, 'counter cleared');
        self::assertSame((int) ceil($this->clock->now() + 600), $status->cooldownUntil);
        self::assertCount(1, $events);
        self::assertInstanceOf(CooldownStarted::class, $events[0]);

        for ($i = 0; $i < 5; $i++) {
            $this->transport->push($this->ok());
            $result = $this->client($a, $b)->on(Event::COOLDOWN_STARTED, $listen)->complete($this->request());
            self::assertSame('b', $result->model());
        }
        self::assertCount(1, $events);
        $lines = array_filter($this->logger->records(Logger::WARNING), static fn ($r) => str_contains($r['message'], 'cooldown'));
        self::assertCount(1, $lines);

        $this->clock->advance(601);
        $this->transport->push($this->ok());
        self::assertSame('a', $this->client($a, $b)->complete($this->request())->model());
    }

    public function testT102SuccessClearsCounterAndCooledLastRouteSuccessEndsCooldown(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $this->transport->push(MockTransport::status(404), $this->ok());
        $this->failOnce($a);
        $this->client($a)->complete($this->request());
        self::assertSame(0, $this->client($a)->status()->routes[0]->failureCount);

        $this->store->set('llmjson.c.' . $a->id(), (int) $this->clock->now() + 600, 600);
        $this->transport->push($this->ok());
        $result = $this->client($a)->complete($this->request());
        self::assertTrue($result->attempts()[0]->lastRouteOverride);
        self::assertNull($this->client($a)->status()->routes[0]->cooldownUntil);
    }

    public function testT103AccountLevelFailuresNeverCount(): void
    {
        $a = Route::to($this->openAi(), 'a');
        foreach ([MockTransport::status(401), MockTransport::status(402), MockTransport::status(403), MockTransport::status(429, '{"error":{"code":"insufficient_quota"}}')] as $response) {
            $this->transport->push($response);
            $this->failOnce($a);
            $this->clock->advance(61);
        }
        self::assertSame(0, $this->client($a)->status()->routes[0]->failureCount);
        self::assertNull($this->client($a)->status()->routes[0]->cooldownUntil);
    }

    public function testT104StoreKeys(): void
    {
        $one = Route::to($this->openRouter('test-key-openrouter-one'), 'vendor/model-a');
        $two = Route::to($this->openRouter('test-key-openrouter-two'), 'vendor/model-a');
        self::assertNotSame($one->id(), $two->id());
        self::assertNotSame($one->provider()->id(), $two->provider()->id());
        $recorder = new class ($this->clock) implements \IndianOpinion\LlmJson\Store\Store {
            /** @var list<string> */
            public array $keys = [];
            private MemoryStore $inner;

            public function __construct(\IndianOpinion\LlmJson\Clock\Clock $clock)
            {
                $this->inner = new MemoryStore($clock);
            }

            public function get(string $key): ?int
            {
                $this->keys[] = $key;

                return $this->inner->get($key);
            }

            public function set(string $key, int $value, int $ttl): void
            {
                $this->keys[] = $key;
                $this->inner->set($key, $value, $ttl);
            }

            public function delete(string $key): void
            {
                $this->keys[] = $key;
                $this->inner->delete($key);
            }

            public function increment(string $key, int $ttl): int
            {
                $this->keys[] = $key;

                return $this->inner->increment($key, $ttl);
            }

            public function add(string $key, int $value, int $ttl): bool
            {
                $this->keys[] = $key;

                return $this->inner->add($key, $value, $ttl);
            }
        };
        $this->transport->push($this->fixture('openrouter-402'), MockTransport::status(404));
        try {
            $this->client($one, $two)->withStore($recorder)->complete($this->request());
        } catch (ChainExhaustedException) {
        }
        self::assertNotEmpty($recorder->keys);
        foreach (array_unique($recorder->keys) as $key) {
            self::assertMatchesRegularExpression('/^llmjson\.[a-z]\.[0-9a-f]{16}$|^llmjson\.n\.\d{8}$/', $key);
            self::assertLessThanOrEqual(64, strlen($key));
            self::assertStringNotContainsString('test-key', $key);
            self::assertStringNotContainsString(substr($one->provider()->keyFingerprint(), 0, 12), $key);
        }
        self::assertContains('llmjson.f.' . $two->id(), $recorder->keys);
        self::assertNotContains('llmjson.f.' . $one->id(), $recorder->keys);

        $custom = Options::defaults()->withStoreKeyPrefix('myapp_llm');
        self::assertSame('myapp_llm', $custom->storeKeyPrefix());
    }

    public function testT110BrokenStoreFailsOpen(): void
    {
        $broken = new CountingStore(new MemoryStore($this->clock), true);
        $this->transport->push(MockTransport::status(404), $this->ok(), $this->ok());
        $client = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->withStore($broken);
        $result = $client->complete($this->request());
        self::assertSame('b', $result->model());
        self::assertNotEmpty($result->storeErrors());
        self::assertStringContainsString('cache server went away', $result->storeErrors()[0]);
        $client->complete($this->request());
        $warnings = array_filter($this->logger->records(Logger::WARNING), static fn ($r) => str_contains($r['message'], 'Store failed'));
        self::assertCount(1, $warnings, 'throttled');
        self::assertNotEmpty($client->status()->storeErrors);
    }

    public function testT111FrozenClockExpiresEverything(): void
    {
        $a = Route::to($this->openRouter(), 'a');
        $this->transport->push(MockTransport::status(404), MockTransport::status(404), $this->fixture('openrouter-402'));
        $this->failOnce($a);
        $this->failOnce($a);
        $this->clock->advance(601);
        $this->failOnce($a);
        $status = $this->client($a)->status()->routes[0];
        self::assertNotNull($status->holdUntil);
        $this->clock->advance(61);
        $status = $this->client($a)->status()->routes[0];
        self::assertNull($status->holdUntil);
        self::assertNull($status->cooldownUntil);
        self::assertSame(0, $status->failureCount);
    }

    public function testT112StatusAndReset(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $b = Route::to($this->openAi(), 'b');
        $this->transport->push(MockTransport::status(404), MockTransport::status(404), MockTransport::status(404), MockTransport::status(404));
        $this->failOnce($a, $b);
        $this->failOnce($a, $b);
        $client = $this->client($a, $b);
        $status = $client->status();
        self::assertCount(2, $status->routes);
        self::assertSame('a', $status->routes[0]->model);
        self::assertNotNull($status->routes[0]->cooldownUntil);
        self::assertNotNull($status->routes[1]->cooldownUntil);
        self::assertSame(2, $status->chainFailuresToday);

        $client->resetCooldown($a);
        self::assertNull($client->status()->routes[0]->cooldownUntil);
        self::assertNotNull($client->status()->routes[1]->cooldownUntil);
        $client->resetCooldowns();
        self::assertNull($client->status()->routes[1]->cooldownUntil);
    }

    public function testT113ReliabilityOff(): void
    {
        $counting = new CountingStore(new MemoryStore($this->clock));
        $this->transport->push(MockTransport::status(500), $this->ok(['title' => "x\u{200B}", 'paragraphs' => [], 'tags' => []]), $this->ok());
        $client = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'), Route::to($this->openAi(), 'c'))
            ->withStore($counting)
            ->withOptions(Options::defaults()->withReliability(false));
        $result = $client->complete($this->request());
        self::assertSame([0, 1, 2], array_map(static fn ($a) => $a->routeIndex, $result->attempts()));
        self::assertSame(Outcome::GuardRejected, $result->attempts()[1]->outcome, 'guard still on');
        self::assertSame(0, $counting->operations);
        self::assertSame([], $this->sleeper->delays());
        foreach ($this->transport->requests() as $request) {
            self::assertSame(8192, $request->json()['max_completion_tokens']);
        }
    }
}
