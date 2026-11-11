<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * A non-success HTTP status. The excerpt is the first 300 characters of the
 * body with anything key-shaped redacted.
 */
class HttpException extends AttemptException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $excerpt = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function excerpt(): string
    {
        return $this->excerpt;
    }
}
