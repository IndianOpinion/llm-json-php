<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

/**
 * State kept for the life of the process, shared by a client and its copies.
 *
 * @internal
 */
final class ProcessMemory
{
    /** @var array<string, float> route id => downgrade expiry */
    public array $downgrades = [];
    /** @var array<string, true> schema fingerprints already logged */
    public array $loggedSchemas = [];
    /** @var array<string, float> throttle key => time it may log again */
    public array $throttle = [];
}
