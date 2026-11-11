<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedAttemptException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\HttpException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Skip;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** PRD 4.6, tests T51 to T60. */
final class StatusHandlingTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function rejectedStatuses(): iterable
    {
        foreach ([400, 404, 409, 413, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('rejectedStatuses')]
    public function testT51RejectedMovesOnWithoutRetryAndCounts(int $status): void
    {
        $a = Route::to($this->openAi(), 'a');
        $this->transport->push(MockTransport::status($status, '{"error":{"message":"bad request"}}'), $this->ok());
        $result = $this->client($a, Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame(Outcome::RequestRejected, $result->attempts()[0]->outcome);
        self::assertSame($status, $result->attempts()[0]->httpStatus);
        self::assertSame(1, $result->attempts()[1]->routeIndex);
        self::assertSame(1, $this->client($a)->status()->routes[0]->failureCount);
        self::assertSame([], $this->sleeper->delays());
    }

    public function testT52FormatRejectionDowngradesToJsonAndIsRemembered(): void
    {
        $route = Route::to($this->openAi(), 'gpt-old');
        $this->transport->push($this->fixture('openai-400-format'), $this->ok());
        $result = $this->client($route)->complete($this->request());
        self::assertSame([OutputMode::Strict, OutputMode::Json], array_map(static fn ($a) => $a->mode, $result->attempts()));
        self::assertSame(OutputMode::Json, $result->mode());
        self::assertSame(['type' => 'json_object'], $this->lastBody()['response_format']);
        self::assertTrue($this->client($route)->status()->routes[0]->downgradedToJson);

        // A fresh client in another "process" (new memory) reads the downgrade from the store.
        $this->transport->push($this->ok());
        $result = $this->client($route)->complete($this->request());
        self::assertSame(OutputMode::Json, $result->attempts()[0]->mode);
        self::assertStringContainsString('remembered', $result->modeReason());

        // A route set to strict explicitly is never downgraded.
        $this->transport->push($this->fixture('openai-400-format'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'gpt-x')->withMode(OutputMode::Strict), Route::to($this->openAi(), 'gpt-y'))->complete($this->request());
        self::assertSame(1, $result->attempts()[1]->routeIndex);
    }

    public function testT52DowngradeExpires(): void
    {
        $route = Route::to($this->openAi(), 'gpt-old');
        $this->transport->push($this->fixture('openai-400-format'), $this->ok(), $this->ok());
        $client = $this->client($route);
        $client->complete($this->request());
        $this->clock->advance(86401);
        $result = $this->client($route)->complete($this->request());
        self::assertSame(OutputMode::Strict, $result->mode());
    }

    /** @return iterable<string, array{int}> */
    public static function authStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[DataProvider('authStatuses')]
    public function testT53AuthSkipsProviderAndDoesNotCount(int $status): void
    {
        $a1 = Route::to($this->openAi(), 'a1');
        $a2 = Route::to($this->openAi(), 'a2');
        $b = Route::to($this->openRouter(), 'b');
        $this->transport->push(MockTransport::status($status, '{"error":{"message":"Incorrect API key provided"}}'), $this->ok());
        $result = $this->client($a1, $a2, $b)->complete($this->request());
        self::assertSame(Outcome::AuthenticationFailed, $result->attempts()[0]->outcome);
        self::assertInstanceOf(AuthenticationException::class, $result->attempts()[0]->error);
        self::assertCount(2, $result->attempts());
        self::assertSame('openrouter', $result->provider());
        self::assertSame(Skip::ACCOUNT, $result->skips()[0]->reason);
        self::assertSame('a2', $result->skips()[0]->model);
        self::assertSame(0, $this->client($a1)->status()->routes[0]->failureCount);
    }

    public function testT54Status402IsCreditExhausted(): void
    {
        $this->transport->push($this->fixture('openrouter-402'), $this->ok());
        $result = $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame(Outcome::CreditExhausted, $result->attempts()[0]->outcome);
        self::assertInstanceOf(CreditExhaustedAttemptException::class, $result->attempts()[0]->error);
        self::assertSame('openrouter', $result->exhaustedProviders()[0]->provider);
        self::assertSame(402, $result->exhaustedProviders()[0]->status);
    }

    /** @return iterable<string, array{string}> */
    public static function creditBodies(): iterable
    {
        foreach (['insufficient_quota', 'credit_balance_exhausted', 'insufficient credits', 'Insufficient Credits remaining', 'You exceeded your current quota'] as $text) {
            yield $text => ['{"error":{"message":"' . $text . '"}}'];
        }
        yield 'code 402 in body' => ['{"error":{"code":402,"message":"Payment needed"}}'];
    }

    #[DataProvider('creditBodies')]
    public function testT55Status429WithCreditPattern(string $body): void
    {
        $this->transport->push(MockTransport::status(429, $body), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
        self::assertSame(Outcome::CreditExhausted, $result->attempts()[0]->outcome);
        self::assertSame([], $this->sleeper->delays(), 'no backoff for credit');
    }

    public function testT55CustomPattern(): void
    {
        $provider = $this->openAi()->withCreditPatterns(['wallet is empty']);
        $this->transport->push(MockTransport::status(429, '{"error":{"message":"Your wallet is EMPTY"}}'), $this->ok());
        $result = $this->client(Route::to($provider, 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
        self::assertSame(Outcome::CreditExhausted, $result->attempts()[0]->outcome);
        self::assertContains('insufficient_quota', $provider->creditPatterns());
        self::assertSame(['only this'], $provider->withCreditPatterns(['only this'], true)->creditPatterns());
    }

    public function testT56Status403WithBillingPatternIsCredit(): void
    {
        $this->transport->push(MockTransport::status(403, '{"error":{"code":"billing_not_active","message":"Your account is not active, please check your billing details."}}'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openRouter(), 'b'))->complete($this->request());
        self::assertSame(Outcome::CreditExhausted, $result->attempts()[0]->outcome);
    }

    public function testT57Plain429BacksOffAndRetryAfterAboveCapSkipsRetry(): void
    {
        $this->transport->push($this->fixture('openai-429-rate'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'))->complete($this->request());
        self::assertSame([Outcome::RateLimited, Outcome::Success], array_map(static fn ($a) => $a->outcome, $result->attempts()));
        self::assertCount(1, $this->sleeper->delays());

        $this->transport->push(MockTransport::status(429, 'slow down', ['Retry-After' => '120']), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame(1, $result->attempts()[1]->routeIndex);
        self::assertCount(1, $this->sleeper->delays(), 'no new sleep');

        $this->transport->push(MockTransport::status(429, 'slow down'), $this->ok());
        $this->client(Route::to($this->openAi(), 'a'))->complete($this->request());
        $delay = $this->sleeper->delays()[1];
        self::assertGreaterThanOrEqual(2.0, $delay);
        self::assertLessThanOrEqual(4.0, $delay);
    }

    /** @return iterable<string, array{int}> */
    public static function retryableStatuses(): iterable
    {
        foreach ([408, 425, 500, 502, 503, 504, 522] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retryableStatuses')]
    public function testT58RetryableStatuses(int $status): void
    {
        $this->transport->push(MockTransport::status($status, 'upstream trouble'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'))->complete($this->request());
        self::assertSame(Outcome::ServerError, $result->attempts()[0]->outcome);
        self::assertSame(0, $result->attempts()[1]->routeIndex);
    }

    public function testT59OtherStatusMovesOnAndCounts(): void
    {
        $a = Route::to($this->openAi(), 'a');
        $this->transport->push(MockTransport::status(418, "I'm a teapot"), $this->ok());
        $result = $this->client($a, Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertSame(Outcome::HttpError, $result->attempts()[0]->outcome);
        self::assertSame(1, $result->attempts()[1]->routeIndex);
        self::assertSame(1, $this->client($a)->status()->routes[0]->failureCount);
    }

    public function testT60KeysAreRedactedInExcerpts(): void
    {
        $secret = 'sk-' . str_repeat('A1b2', 10);
        $body = '{"error":{"message":"Bad key ' . $secret . ' sent with Authorization: Bearer abc.def-ghi and api_key=hunter2 ' . str_repeat('x', 400) . '"}}';
        $this->transport->push(MockTransport::status(400, $body), $this->ok());
        $provider = Provider::openAi('test-key-literal-value');
        $this->transport->push(MockTransport::status(400, 'echo of test-key-literal-value'), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        $excerpt = (string) $result->attempts()[0]->errorExcerpt;
        self::assertStringNotContainsString($secret, $excerpt);
        self::assertStringNotContainsString('abc.def-ghi', $excerpt);
        self::assertStringNotContainsString('hunter2', $excerpt);
        self::assertLessThanOrEqual(300, strlen($excerpt));
        $error = $result->attempts()[0]->error;
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame($excerpt, $error->excerpt());

        $result = $this->client(Route::to($provider, 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
        self::assertStringNotContainsString('test-key-literal-value', (string) $result->attempts()[0]->errorExcerpt);
    }

    public function testCreditOnEveryProviderThrowsCreditExhausted(): void
    {
        $this->transport->push($this->fixture('openrouter-402'), $this->fixture('openai-429-quota'));
        $this->expectException(CreditExhaustedException::class);
        $this->client(Route::to($this->openRouter(), 'a'), Route::to($this->openAi(), 'b'))->complete($this->request());
    }
}
