<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The content was not valid JSON. The excerpt holds its first 200
 * characters; it is never put in the message, so it does not reach logs.
 */
final class MalformedOutputException extends AttemptException
{
    public function __construct(string $message, private readonly string $excerpt)
    {
        parent::__construct($message);
    }

    public function excerpt(): string
    {
        return $this->excerpt;
    }
}
