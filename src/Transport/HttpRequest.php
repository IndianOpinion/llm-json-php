<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

/**
 * Everything a transport needs to send one attempt.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $connectTimeout,
        public readonly float $timeout,
        public readonly int $maxResponseBytes,
    ) {
    }

    /** The decoded JSON body, for tests and debugging. */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $headers[$name] = '[redacted]';
            }
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'bodyBytes' => strlen($this->body),
            'connectTimeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
            'maxResponseBytes' => $this->maxResponseBytes,
        ];
    }
}
