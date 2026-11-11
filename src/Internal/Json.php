<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * @internal
 */
final class Json
{
    /** Deepest nesting accepted in model output. */
    public const MAX_DEPTH = 64;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException('Could not encode JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decode a body or content string. Returns a DecodeFailure when it is
     * not JSON within the depth limit.
     *
     * @param positive-int $maxNesting
     * @param bool         $assoc      false decodes objects as stdClass, so {} and [] stay distinct
     */
    public static function decode(string $json, int $maxNesting = self::MAX_DEPTH, bool $assoc = true): mixed
    {
        // json_decode counts the innermost scalar as a level, so N nested
        // containers need a depth of N + 1.
        try {
            return json_decode($json, $assoc, $maxNesting + 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new DecodeFailure();
        }
    }
}
