<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Guard\Violation;
use IndianOpinion\LlmJson\Skip;

/**
 * Everything one call accumulates.
 *
 * @internal
 */
final class CallState
{
    /** @var list<Attempt> */
    public array $attempts = [];
    /** @var list<Skip> */
    public array $skips = [];
    /** @var list<CreditExhaustion> */
    public array $credit = [];
    /** @var array<string, true> provider ids with an account-level failure in this call */
    public array $accountFailed = [];
    /** @var list<string> */
    public array $storeErrors = [];
    /** @var list<string> */
    public array $listenerErrors = [];
    /** @var list<Violation> */
    public array $rejections = [];
    public ?\Throwable $lastError = null;
    public string $phase = 'first';
    public int $routesTried = 0;
    public int $routesRefused = 0;
    public bool $deadlineHit = false;

    public function __construct(
        public readonly string $purpose,
        public readonly float $start,
        public readonly float $deadline,
    ) {
    }
}
