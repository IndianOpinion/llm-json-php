<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * Event names for Client::on(). Each listener receives one payload object,
 * named next to the constant.
 */
final class Event
{
    /** Payload: Attempt. After every attempt, success or failure. */
    public const ATTEMPT_FINISHED = 'attempt.finished';
    /** Payload: RetryScheduled. Before the sleep that precedes a retry. */
    public const RETRY_SCHEDULED = 'retry.scheduled';
    /** Payload: Skip. When a route is passed over. */
    public const ROUTE_SKIPPED = 'route.skipped';
    /** Payload: CooldownStarted. Once, when a route goes on cooldown. */
    public const COOLDOWN_STARTED = 'cooldown.started';
    /** Payload: CreditExhaustion. Every time a provider reports no credit. */
    public const CREDIT_EXHAUSTED = 'credit.exhausted';
    /** Payload: Guard\Repair. For each repair the guard made on the returned data. */
    public const GUARD_REPAIRED = 'guard.repaired';
    /** Payload: Guard\Violation. For each rejection, on any attempt. */
    public const GUARD_REJECTED = 'guard.rejected';
    /** Payload: Exception\ChainExhaustedException. Just before it is thrown. */
    public const CHAIN_EXHAUSTED = 'chain.exhausted';

    /** @var list<string> */
    public const ALL = [
        self::ATTEMPT_FINISHED,
        self::RETRY_SCHEDULED,
        self::ROUTE_SKIPPED,
        self::COOLDOWN_STARTED,
        self::CREDIT_EXHAUSTED,
        self::GUARD_REPAIRED,
        self::GUARD_REJECTED,
        self::CHAIN_EXHAUSTED,
    ];

    private function __construct()
    {
    }
}
