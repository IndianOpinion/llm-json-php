<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

final class Status
{
    /**
     * @param list<RouteStatus> $routes
     * @param list<string>      $storeErrors
     */
    public function __construct(
        public readonly array $routes,
        public readonly int $chainFailuresToday,
        public readonly array $storeErrors = [],
    ) {
    }
}
