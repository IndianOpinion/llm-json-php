<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The request did not get an HTTP response: DNS, connect, reset, TLS,
 * timeout, or a body over the size cap.
 */
final class TransportException extends AttemptException
{
    public function __construct(
        string $message,
        private readonly bool $tlsVerification = false,
        private readonly bool $bodyTooLarge = false,
        private readonly bool $timeout = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True when the TLS certificate could not be verified. Never retried. */
    public function isTlsVerificationFailure(): bool
    {
        return $this->tlsVerification;
    }

    /** True when the response body passed the size cap and was aborted. */
    public function isBodyTooLarge(): bool
    {
        return $this->bodyTooLarge;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
