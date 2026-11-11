<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Log;

/**
 * Writes nothing anywhere. The default.
 */
final class NullLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
    }
}
