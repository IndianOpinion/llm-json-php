<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

/**
 * One guard rejection: the rule, the JSON pointer and a short reason. The
 * message never contains the output text.
 */
final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $path,
        public readonly string $message,
    ) {
    }
}
