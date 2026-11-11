<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Log;

/**
 * The library's tiny logger interface. Levels are the PSR-3 level names,
 * so any PSR-3 logger fits through Psr3Logger.
 */
interface Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;
}
