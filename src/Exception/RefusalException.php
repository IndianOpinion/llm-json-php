<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The model refused. The refusal text is kept, cut to 500 characters.
 */
final class RefusalException extends AttemptException
{
    public function __construct(private readonly string $refusal)
    {
        parent::__construct('The model refused the request.');
    }

    public function refusal(): string
    {
        return $this->refusal;
    }
}
