<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;

/**
 * Store keys: prefix, a type letter and a hash. Short, ASCII, valid as
 * PSR-16 keys and transient names, and never carrying key material.
 *
 * @internal
 */
final class Keys
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function failures(Route $route): string
    {
        return $this->prefix . '.f.' . $route->id();
    }

    public function cooldown(Route $route): string
    {
        return $this->prefix . '.c.' . $route->id();
    }

    public function downgrade(Route $route): string
    {
        return $this->prefix . '.d.' . $route->id();
    }

    public function hold(Provider $provider): string
    {
        return $this->prefix . '.h.' . $provider->id();
    }

    public function creditLog(Provider $provider): string
    {
        return $this->prefix . '.t.' . $provider->id();
    }

    public function day(float $now): string
    {
        return $this->prefix . '.n.' . gmdate('Ymd', (int) $now);
    }
}
