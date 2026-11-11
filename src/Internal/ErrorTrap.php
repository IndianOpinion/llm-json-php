<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

/**
 * Runs a callable with PHP warnings turned into a caught message, so that
 * file and stream functions can be used without the @ operator.
 *
 * @internal
 */
final class ErrorTrap
{
    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return array{0: T, 1: ?string} the return value and the first warning, if any
     */
    public static function run(callable $fn): array
    {
        $warning = null;
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });
        try {
            $value = $fn();
        } finally {
            restore_error_handler();
        }

        return [$value, $warning];
    }
}
