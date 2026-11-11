<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

/**
 * Status, headers (names in lower case) and body.
 */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $normal = [];
        foreach ($headers as $name => $value) {
            $normal[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : $value;
        }
        $this->headers = $normal;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
