<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The output did not match the schema. The path is a JSON pointer.
 */
final class SchemaViolationException extends AttemptException
{
    public function __construct(string $message, private readonly string $path)
    {
        parent::__construct($message);
    }

    public function path(): string
    {
        return $this->path;
    }
}
