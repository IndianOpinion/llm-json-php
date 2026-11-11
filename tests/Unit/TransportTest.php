<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Transport\CurlTransport;
use IndianOpinion\LlmJson\Transport\HttpRequest;
use IndianOpinion\LlmJson\Transport\StreamTransport;
use IndianOpinion\LlmJson\Transport\TransportFactory;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** PRD 4.4, tests T23 to T29 (the live-server half of T23 to T26 is in Integration). */
final class TransportTest extends TestCase
{
    private function http(): HttpRequest
    {
        return new HttpRequest('POST', 'https://api.example.com/v1/chat/completions', ['Authorization' => 'Bearer test-key-x', 'Content-Type' => 'application/json'], '{}', 10.0, 90.0, 8 * 1024 * 1024);
    }

    public function testT23CurlOptions(): void
    {
        $options = (new CurlTransport())->options($this->http());
        self::assertSame(10_000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(90_000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertContains('Authorization: Bearer test-key-x', $options[CURLOPT_HTTPHEADER]);
    }

    public function testStreamOptions(): void
    {
        $options = (new StreamTransport())->contextOptions($this->http());
        self::assertSame(0, $options['http']['follow_location']);
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertTrue($options['ssl']['verify_peer_name']);
        self::assertTrue($options['http']['ignore_errors']);
    }

    public function testRequestDebugInfoHidesAuthorization(): void
    {
        self::assertStringNotContainsString('test-key-x', print_r($this->http(), true));
    }

    public function testT26TimeoutRetriedTlsNot(): void
    {
        $this->transport->push(new TransportException('timed out', timeout: true), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
        self::assertSame([Outcome::TransportError, Outcome::Success], array_map(static fn ($a) => $a->outcome, $result->attempts()));

        $this->transport->push(new TransportException('certificate verify failed', tlsVerification: true), $this->ok());
        $result = $this->client(Route::to($this->openAi(), 'gpt-test'), Route::to($this->openAi(), 'gpt-other'))->complete($this->request());
        self::assertSame(Outcome::TlsVerificationFailed, $result->attempts()[0]->outcome);
        self::assertSame(0, $result->attempts()[0]->routeIndex);
        self::assertSame(1, $result->attempts()[1]->routeIndex, 'no retry on the same route after a TLS verification failure');
    }

    public function testT27NoTransportAvailable(): void
    {
        try {
            TransportFactory::choose(false, false);
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('ext-curl', $e->getMessage());
            self::assertStringContainsString('WordPressTransport', $e->getMessage());
        }
        self::assertInstanceOf(StreamTransport::class, TransportFactory::choose(false, true));
        self::assertInstanceOf(CurlTransport::class, TransportFactory::choose(true, true));
    }

    public function testT28WordPressTransport(): void
    {
        require_once __DIR__ . '/../stubs/wordpress.php';
        llmjson_wp_reset();
        $GLOBALS['llmjson_wp']['responses'] = [
            new \WP_Error('http_request_failed', 'cURL error 7: Failed to connect'),
            ['response' => ['code' => 200], 'headers' => ['X-Request-Id' => 'req_wp'], 'body' => (string) json_encode([
                'id' => 'x', 'model' => 'gpt-test',
                'choices' => [['message' => ['content' => json_encode($this->okData())], 'finish_reason' => 'stop']],
            ])],
        ];
        $result = $this->client(Route::to($this->openAi(), 'gpt-test'))->withTransport(new WordPressTransport())->complete($this->request());
        self::assertSame(Outcome::TransportError, $result->attempts()[0]->outcome);
        self::assertStringContainsString('Failed to connect', (string) $result->attempts()[0]->error?->getMessage());
        self::assertSame('req_wp', $result->attempts()[1]->requestId);
        self::assertSame($this->okData(), $result->data());
        $call = $GLOBALS['llmjson_wp']['calls'][1];
        self::assertSame(0, $call['args']['redirection']);
        self::assertTrue($call['args']['sslverify']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $call['url']);
        self::assertSame('Bearer test-key-openai-one', $call['args']['headers']['Authorization']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT28WordPressTransportNeedsWordPress(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('wp_remote_post');
        new WordPressTransport();
    }

    public function testT29ForeignExceptionIsWrapped(): void
    {
        $this->transport->push(new \RuntimeException('socket exploded'), new \RuntimeException('again'));
        try {
            $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            $error = $e->attempts()[0]->error;
            self::assertInstanceOf(TransportException::class, $error);
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            self::assertSame('socket exploded', $error->getPrevious()->getMessage());
            self::assertCount(2, $e->attempts(), 'wrapped failures are retryable');
        }
    }
}
