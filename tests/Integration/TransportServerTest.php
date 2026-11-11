<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Integration;

use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Transport\CurlTransport;
use IndianOpinion\LlmJson\Transport\HttpRequest;
use IndianOpinion\LlmJson\Transport\StreamTransport;
use IndianOpinion\LlmJson\Transport\Transport;
use PHPUnit\Framework\Attributes\DataProvider;

/** T23 to T26 against a real local server, for both bundled HTTP transports. */
final class TransportServerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = LocalServer::start();
    }

    /** @return iterable<string, array{0: callable(): Transport}> */
    public static function transports(): iterable
    {
        yield 'curl' => [static fn (): Transport => new CurlTransport()];
        yield 'streams' => [static fn (): Transport => new StreamTransport()];
    }

    private function http(string $path, float $timeout = 5.0, int $max = 1024 * 1024): HttpRequest
    {
        return new HttpRequest('POST', $this->base . $path . '/chat/completions', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer local-test', 'User-Agent' => 'llm-json-php/test'], '{"hello":"world"}', 2.0, $timeout, $max);
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testT23RealRequestSendsHeadersAndBody(callable $make): void
    {
        $response = $make()->send($this->http('/echo'));
        self::assertSame(200, $response->status);
        $content = json_decode((string) json_decode($response->body, true)['choices'][0]['message']['content'], true);
        self::assertSame('llm-json-php/test', $content['title']);
        self::assertSame('{"hello":"world"}', $content['paragraphs'][0]);
        self::assertSame('Bearer local-test', $content['tags'][0]);
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testT24RedirectIsNotFollowed(callable $make): void
    {
        $response = $make()->send($this->http('/redirect'));
        self::assertSame(301, $response->status);
        self::assertSame('moved', trim($response->body));
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testT25BodyOverCapIsAborted(callable $make): void
    {
        try {
            $make()->send($this->http('/big'));
            self::fail('expected exception');
        } catch (TransportException $e) {
            self::assertTrue($e->isBodyTooLarge());
        }
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testT26TimeoutIsATransportFailure(callable $make): void
    {
        try {
            $make()->send($this->http('/slow', 1.0));
            self::fail('expected exception');
        } catch (TransportException $e) {
            self::assertTrue($e->isTimeout(), $e->getMessage());
            self::assertFalse($e->isTlsVerificationFailure());
        }
    }

    public function testEndToEndThroughCurlWithRedirectAndBigBody(): void
    {
        $redirect = Provider::generic('redirecting', $this->base . '/redirect', keyless: true);
        $big = Provider::generic('big', $this->base . '/big', keyless: true);
        $ok = Provider::generic('ok', $this->base . '/ok', keyless: true);
        $client = $this->client(Route::to($redirect, 'm'), Route::to($big, 'm'), Route::to($ok, 'm'))
            ->withTransport(new CurlTransport())
            ->withOptions(Options::defaults()->withMaxResponseBytes(1024 * 1024));
        $result = $client->complete($this->request());
        self::assertSame('Local', $result->data()['title']);
        $outcomes = array_map(static fn ($a) => $a->outcome, $result->attempts());
        self::assertSame([Outcome::Redirect, Outcome::InvalidResponse, Outcome::InvalidResponse, Outcome::Success], $outcomes);
        self::assertSame(301, $result->attempts()[0]->httpStatus);
        self::assertSame('req_local_1', $result->attempts()[3]->requestId);
    }
}
