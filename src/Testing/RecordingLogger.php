<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Testing;

use IndianOpinion\LlmJson\Log\Logger;

/**
 * Keeps every log line in memory.
 */
final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function records(?string $level = null): array
    {
        if ($level === null) {
            return $this->records;
        }

        return array_values(array_filter($this->records, static fn (array $r): bool => $r['level'] === $level));
    }

    /** Everything logged, as one string, for "does it contain" checks. */
    public function dump(): string
    {
        return (string) json_encode($this->records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
