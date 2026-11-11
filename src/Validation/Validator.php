<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Validation;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Internal\Text;
use IndianOpinion\LlmJson\Schema;

/**
 * Validates decoded output against the keywords allowed in strict
 * structured outputs. Unsupported keywords are ignored.
 *
 * Pass the output decoded with objects (json_decode($json, false)) to tell
 * a JSON object from a JSON array exactly; the client does. An associative
 * PHP array is accepted too, but then {} and [] look the same, and so do an
 * array and an object whose keys are "0", "1", ...
 */
final class Validator
{
    public const MAX_REF_DEPTH = 32;

    /** True while validating a tree decoded with objects: then every PHP array is a JSON array. */
    private bool $exact = false;

    /**
     * @param array<mixed>|\stdClass $data
     *
     * @throws SchemaViolationException
     * @throws ConfigurationException on a $ref chain deeper than 32
     */
    public function validate(Schema $schema, array|\stdClass $data): void
    {
        $this->exact = $data instanceof \stdClass;
        $error = $this->check($schema, $schema->toArray(), $data, '', 0);
        if ($error !== null) {
            throw new SchemaViolationException(sprintf('Schema violation at %s: %s', $error[0] === '' ? '/' : $error[0], $error[1]), $error[0]);
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array{0: string, 1: string}|null
     */
    private function check(Schema $schema, array $node, mixed $value, string $path, int $refDepth): ?array
    {
        // $refDepth counts $ref hops taken without descending into the data,
        // so it only grows on a reference cycle. Recursive schemas (a tree of
        // nodes) are bounded by the JSON nesting limit instead.
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            if ($refDepth >= self::MAX_REF_DEPTH) {
                throw new ConfigurationException(sprintf('Schema "%s": $ref nesting deeper than %d at %s.', $schema->name(), self::MAX_REF_DEPTH, $path === '' ? '/' : $path));
            }
            $error = $this->check($schema, $schema->resolveRef($node['$ref']), $value, $path, $refDepth + 1);
            if ($error !== null) {
                return $error;
            }
        }

        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            $first = null;
            $matched = false;
            foreach ($node['anyOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $error = $this->check($schema, $branch, $value, $path, $refDepth);
                if ($error === null) {
                    $matched = true;
                    break;
                }
                $first ??= $error;
            }
            if (!$matched) {
                return $first ?? [$path, 'matches no anyOf branch'];
            }
        }

        if (isset($node['type'])) {
            $types = (array) $node['type'];
            $ok = false;
            foreach ($types as $type) {
                if ($this->isType($value, (string) $type)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return [$path, sprintf('expected %s, got %s', implode(' or ', array_map('strval', $types)), self::describe($value))];
            }
        }

        if (array_key_exists('const', $node) && !self::equals($node['const'], $value)) {
            return [$path, 'value differs from const'];
        }
        if (isset($node['enum']) && is_array($node['enum'])) {
            $found = false;
            foreach ($node['enum'] as $allowed) {
                if (self::equals($allowed, $value)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return [$path, 'value is not in enum'];
            }
        }

        if (is_string($value)) {
            $length = Text::length($value);
            if (isset($node['minLength']) && $length < (int) $node['minLength']) {
                return [$path, sprintf('string shorter than %d characters', (int) $node['minLength'])];
            }
            if (isset($node['maxLength']) && $length > (int) $node['maxLength']) {
                return [$path, sprintf('string longer than %d characters', (int) $node['maxLength'])];
            }
            if (isset($node['pattern']) && is_string($node['pattern']) && preg_match($schema->patternRegex($node['pattern']), $value) !== 1) {
                return [$path, 'string does not match pattern'];
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($node['minimum']) && $value < $node['minimum']) {
                return [$path, 'number below minimum'];
            }
            if (isset($node['maximum']) && $value > $node['maximum']) {
                return [$path, 'number above maximum'];
            }
            if (isset($node['exclusiveMinimum']) && is_numeric($node['exclusiveMinimum']) && $value <= $node['exclusiveMinimum']) {
                return [$path, 'number not above exclusiveMinimum'];
            }
            if (isset($node['exclusiveMaximum']) && is_numeric($node['exclusiveMaximum']) && $value >= $node['exclusiveMaximum']) {
                return [$path, 'number not below exclusiveMaximum'];
            }
            if (isset($node['multipleOf']) && is_numeric($node['multipleOf']) && (float) $node['multipleOf'] > 0) {
                $ratio = $value / (float) $node['multipleOf'];
                if (abs($ratio - round($ratio)) > 1e-9 * max(1.0, abs($ratio))) {
                    return [$path, 'number is not a multiple of multipleOf'];
                }
            }
        }

        if ($value instanceof \stdClass) {
            $error = $this->checkObject($schema, $node, get_object_vars($value), $path);
            if ($error !== null) {
                return $error;
            }
        } elseif (is_array($value)) {
            $isList = array_is_list($value);
            $types = isset($node['type']) ? (array) $node['type'] : [];
            $treatAsObject = !$isList || (!$this->exact && $value === [] && (in_array('object', $types, true) || isset($node['properties'])));
            if ($treatAsObject) {
                $error = $this->checkObject($schema, $node, $value, $path);
            } else {
                $error = $this->checkArray($schema, $node, $value, $path);
            }
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @param array<mixed> $value
     *
     * @return array{0: string, 1: string}|null
     */
    private function checkObject(Schema $schema, array $node, array $value, string $path): ?array
    {
        foreach ((array) ($node['required'] ?? []) as $key) {
            if (!array_key_exists((string) $key, $value)) {
                return [$path . '/' . self::escape((string) $key), 'required key is missing'];
            }
        }
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $additional = $node['additionalProperties'] ?? true;
        foreach ($value as $key => $child) {
            $key = (string) $key;
            $childPath = $path . '/' . self::escape($key);
            if (array_key_exists($key, $properties) && is_array($properties[$key])) {
                $error = $this->check($schema, $properties[$key], $child, $childPath, 0);
                if ($error !== null) {
                    return $error;
                }
                continue;
            }
            if ($additional === false) {
                return [$childPath, 'key is not allowed (additionalProperties is false)'];
            }
            if (is_array($additional)) {
                $error = $this->check($schema, $additional, $child, $childPath, 0);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @param list<mixed>  $value
     *
     * @return array{0: string, 1: string}|null
     */
    private function checkArray(Schema $schema, array $node, array $value, string $path): ?array
    {
        $count = count($value);
        if (isset($node['minItems']) && $count < (int) $node['minItems']) {
            return [$path, sprintf('fewer than %d items', (int) $node['minItems'])];
        }
        if (isset($node['maxItems']) && $count > (int) $node['maxItems']) {
            return [$path, sprintf('more than %d items', (int) $node['maxItems'])];
        }
        if (isset($node['items']) && is_array($node['items'])) {
            foreach ($value as $i => $item) {
                $error = $this->check($schema, $node['items'], $item, $path . '/' . $i, 0);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof \stdClass || (!$this->exact && is_array($value) && ($value === [] || !array_is_list($value))),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        $a = self::plain($a);
        $b = self::plain($b);
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /** Objects decoded as stdClass become associative arrays, for comparison. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value instanceof \stdClass => 'object',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            default => 'unknown',
        };
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
