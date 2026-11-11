<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Schema;

/**
 * JSON pointer paths with "*" for every item of an array.
 *
 * @internal
 */
final class Paths
{
    /**
     * @return list<string>
     */
    public static function parse(string $path): array
    {
        if ($path === '' || $path[0] !== '/' || $path === '/') {
            throw new ConfigurationException(sprintf('Guard path "%s" must be a JSON pointer such as /title or /items/*/text.', $path));
        }
        $segments = [];
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '') {
                throw new ConfigurationException(sprintf('Guard path "%s" has an empty segment.', $path));
            }
            $segments[] = str_replace(['~1', '~0'], ['/', '~'], $segment);
        }

        return $segments;
    }

    /**
     * Throws when the path does not exist in the schema.
     */
    public static function assertInSchema(string $path, Schema $schema): void
    {
        if (!self::existsIn($schema, $schema->toArray(), self::parse($path), 0)) {
            throw new ConfigurationException(sprintf('Guard path "%s" is not in schema "%s". A typo here would turn the check off silently.', $path, $schema->name()));
        }
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $segments
     */
    private static function existsIn(Schema $schema, array $node, array $segments, int $depth): bool
    {
        if ($depth > 32) {
            return false;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return self::existsIn($schema, $schema->resolveRef($node['$ref']), $segments, $depth + 1);
        }
        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            foreach ($node['anyOf'] as $branch) {
                if (is_array($branch) && self::existsIn($schema, $branch, $segments, $depth + 1)) {
                    return true;
                }
            }
        }
        if ($segments === []) {
            return true;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            return is_array($node['items'] ?? null) && self::existsIn($schema, $node['items'], $segments, $depth + 1);
        }
        $properties = $node['properties'] ?? null;

        return is_array($properties) && is_array($properties[$segment] ?? null)
            && self::existsIn($schema, $properties[$segment], $segments, $depth + 1);
    }

    /**
     * Every concrete location the path names in the data, as segment lists.
     *
     * @param array<mixed> $data
     *
     * @return list<list<string|int>>
     */
    public static function resolve(array $data, string $path): array
    {
        $found = [[[], $data]];
        foreach (self::parse($path) as $segment) {
            $next = [];
            foreach ($found as [$at, $value]) {
                if (!is_array($value)) {
                    continue;
                }
                if ($segment === '*') {
                    if (!array_is_list($value)) {
                        continue;
                    }
                    foreach ($value as $i => $item) {
                        $next[] = [array_merge($at, [$i]), $item];
                    }
                } elseif (array_key_exists($segment, $value)) {
                    $next[] = [array_merge($at, [$segment]), $value[$segment]];
                }
            }
            $found = $next;
        }

        return array_map(static fn (array $pair): array => $pair[0], $found);
    }

    /**
     * @param array<mixed>      $data
     * @param list<string|int>  $segments
     */
    public static function get(array $data, array $segments): mixed
    {
        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<mixed>      $data
     * @param list<string|int>  $segments
     */
    public static function set(array &$data, array $segments, mixed $newValue): void
    {
        $ref = &$data;
        foreach ($segments as $segment) {
            if (!is_array($ref)) {
                return;
            }
            $ref = &$ref[$segment];
        }
        $ref = $newValue;
    }

    /**
     * @param list<string|int> $segments
     */
    public static function pointer(array $segments): string
    {
        return '/' . implode('/', array_map(static fn (string|int $s): string => str_replace(['~', '/'], ['~0', '~1'], (string) $s), $segments));
    }
}
