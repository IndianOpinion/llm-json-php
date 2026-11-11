<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Log;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * Adapter for a PSR-3 logger. Usable only when psr/log is installed; the
 * library does not depend on it. Client::withLogger() wraps a PSR-3 logger
 * in this adapter for you.
 */
final class Psr3Logger implements Logger
{
    private const PSR_INTERFACE = 'Psr\\Log\\LoggerInterface';

    private object $logger;

    public function __construct(object $logger)
    {
        if (!self::available()) {
            throw new ConfigurationException('Psr3Logger needs psr/log. Install it, or implement IndianOpinion\\LlmJson\\Log\\Logger directly.');
        }
        if (!is_a($logger, self::PSR_INTERFACE)) {
            throw new ConfigurationException(sprintf('Psr3Logger needs a %s, got %s.', self::PSR_INTERFACE, $logger::class));
        }
        $this->logger = $logger;
    }

    public static function available(): bool
    {
        return interface_exists(self::PSR_INTERFACE);
    }

    public static function accepts(object $logger): bool
    {
        return self::available() && is_a($logger, self::PSR_INTERFACE);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $callable = [$this->logger, 'log'];
        if (is_callable($callable)) {
            $callable($level, $message, $context);
        }
    }
}
