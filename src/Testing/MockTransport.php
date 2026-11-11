<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Testing;

use IndianOpinion\LlmJson\Transport\HttpRequest;
use IndianOpinion\LlmJson\Transport\HttpResponse;
use IndianOpinion\LlmJson\Transport\Transport;

/**
 * A transport that answers from a queue, for tests without network.
 *
 * Each queued entry is an HttpResponse, a Throwable to throw, or a callable
 * that receives the HttpRequest and returns either. Every request is kept.
 */
final class MockTransport implements Transport
{
    /** @var list<HttpResponse|\Throwable|callable(HttpRequest): (HttpResponse|\Throwable)> */
    private array $queue = [];
    /** @var list<HttpRequest> */
    private array $requests = [];

    /**
     * @param HttpResponse|\Throwable|callable(HttpRequest): (HttpResponse|\Throwable) ...$entries
     */
    public function __construct(HttpResponse|\Throwable|callable ...$entries)
    {
        $this->queue = array_values($entries);
    }

    /**
     * @param HttpResponse|\Throwable|callable(HttpRequest): (HttpResponse|\Throwable) ...$entries
     */
    public function push(HttpResponse|\Throwable|callable ...$entries): self
    {
        foreach ($entries as $entry) {
            $this->queue[] = $entry;
        }

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException(sprintf('MockTransport queue is empty (request %d to %s).', count($this->requests), $request->url));
        }
        $entry = array_shift($this->queue);
        if (is_callable($entry)) {
            $entry = $entry($request);
        }
        if ($entry instanceof \Throwable) {
            throw $entry;
        }

        return $entry;
    }

    /** @return list<HttpRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    /**
     * A chat completions response with the given content.
     *
     * @param array<mixed>|string       $content array is JSON-encoded
     * @param array<string, mixed>|null $usage
     * @param array<string, string>     $headers
     */
    public static function completion(
        array|string $content,
        string $finishReason = 'stop',
        ?array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        string $model = 'test-model',
        array $headers = [],
    ): HttpResponse {
        $text = is_array($content) ? (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $content;
        $body = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => $finishReason,
            ]],
        ];
        if ($usage !== null) {
            $body['usage'] = $usage;
        }

        return self::json($body, 200, $headers);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function status(int $status, string $body = '', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, $body);
    }

    /**
     * A response saved as JSON: {"status": 200, "headers": {...}, "body": {...} or "..."}.
     */
    public static function fromFixture(string $path): HttpResponse
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read fixture ' . $path);
        }
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($fixture)) {
            throw new \RuntimeException('Fixture is not an object: ' . $path);
        }
        $body = $fixture['body'] ?? '';
        $headers = is_array($fixture['headers'] ?? null) ? $fixture['headers'] : [];

        /** @var array<string, string> $headers */
        return new HttpResponse((int) ($fixture['status'] ?? 200), $headers, is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
