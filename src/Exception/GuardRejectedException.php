<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

use IndianOpinion\LlmJson\Guard\Violation;

/**
 * The guard rejected the output. Holds the first violation and the full list.
 */
final class GuardRejectedException extends AttemptException
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(private readonly array $violations)
    {
        $first = $violations[0] ?? new Violation('G0', '', 'unknown violation');
        parent::__construct(sprintf('Guard rule %s rejected %s: %s', $first->rule, $first->path === '' ? '/' : $first->path, $first->message));
    }

    public function rule(): string
    {
        return $this->violations === [] ? '' : $this->violations[0]->rule;
    }

    public function path(): string
    {
        return $this->violations === [] ? '' : $this->violations[0]->path;
    }

    /** @return list<Violation> */
    public function violations(): array
    {
        return $this->violations;
    }
}
