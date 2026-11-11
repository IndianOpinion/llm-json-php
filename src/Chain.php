<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * An ordered, non-empty list of routes. The first route is the primary.
 *
 * @implements \IteratorAggregate<int, Route>
 */
final class Chain implements \IteratorAggregate, \Countable
{
    /** @var list<Route> */
    private array $routes;

    public function __construct(Route ...$routes)
    {
        if ($routes === []) {
            throw new ConfigurationException('A chain needs at least one route.');
        }
        $this->routes = array_values($routes);
    }

    public static function of(Route ...$routes): self
    {
        return new self(...$routes);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /** @return \ArrayIterator<int, Route> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->routes);
    }
}
