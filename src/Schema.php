<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Internal\Json;

/**
 * A named JSON schema with an object root. Immutable.
 */
final class Schema
{
    /** Keywords the built-in validator checks. */
    public const SUPPORTED_KEYWORDS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'anyOf',
        '$ref', '$defs', 'definitions', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
    ];

    /** Annotations: accepted everywhere, never checked, never reported. */
    public const ANNOTATIONS = ['description', 'title', '$schema', '$id', '$comment', 'examples', 'default'];

    /** Not checked locally, but accepted by providers in strict mode. */
    private const STRICT_EXTRA = ['format'];

    /** @var array<string, mixed> */
    private array $schema;
    private ?string $strictProblem = null;
    private bool $strictChecked = false;
    /** @var list<string> */
    private array $unsupported = [];
    /** @var array<string, string> */
    private array $patterns = [];
    private string $fingerprint;

    /**
     * @param array<string, mixed> $schema
     */
    private function __construct(private readonly string $name, array $schema)
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) !== 1) {
            throw new ConfigurationException('Schema name must be 1 to 64 letters, digits, underscores or hyphens.');
        }
        if ($schema === [] || array_is_list($schema)) {
            throw new ConfigurationException(sprintf('Schema "%s" must be a JSON object.', $name));
        }
        $type = $schema['type'] ?? null;
        if ($type !== 'object') {
            throw new ConfigurationException(sprintf('Schema "%s" must have type "object" at the root.', $name));
        }
        $this->schema = $schema;
        $this->fingerprint = substr(hash('sha256', Json::encode($schema)), 0, 16);
        $unsupported = [];
        $this->walk($schema, '', $unsupported);
        $this->unsupported = array_values(array_unique($unsupported));
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function fromArray(string $name, array $schema): self
    {
        return new self($name, $schema);
    }

    public static function fromJson(string $name, string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException(sprintf('Schema "%s" is not valid JSON: %s', $name, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ConfigurationException(sprintf('Schema "%s" must be a JSON object.', $name));
        }
        /** @var array<string, mixed> $decoded */
        return new self($name, $decoded);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->schema;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Null when the schema can be sent in strict mode; otherwise the first
     * offending JSON path in the schema and the reason.
     */
    public function strictIncompatibility(): ?string
    {
        if (!$this->strictChecked) {
            $this->strictProblem = $this->findStrictProblem($this->schema, '');
            $this->strictChecked = true;
        }

        return $this->strictProblem;
    }

    public function isStrictCompatible(): bool
    {
        return $this->strictIncompatibility() === null;
    }

    /**
     * Keywords the validator ignores (for example format or if).
     *
     * @return list<string>
     */
    public function unsupportedKeywords(): array
    {
        return $this->unsupported;
    }

    /**
     * Resolve a local reference such as "#/$defs/item" or "#".
     *
     * @return array<string, mixed>
     */
    public function resolveRef(string $ref): array
    {
        if ($ref === '#') {
            return $this->schema;
        }
        if (!str_starts_with($ref, '#/')) {
            throw new ConfigurationException(sprintf('Schema "%s": only local references are supported, got "%s".', $this->name, $ref));
        }
        $node = $this->schema;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new ConfigurationException(sprintf('Schema "%s": reference "%s" does not resolve.', $this->name, $ref));
            }
            $node = $node[$segment];
        }
        if (!is_array($node)) {
            throw new ConfigurationException(sprintf('Schema "%s": reference "%s" is not a schema.', $this->name, $ref));
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * The compiled PCRE for a schema pattern.
     *
     * @internal
     */
    public function patternRegex(string $pattern): string
    {
        return $this->patterns[$pattern] ?? throw new ConfigurationException(sprintf('Schema "%s": pattern was not compiled.', $this->name));
    }

    /**
     * @param array<mixed>  $node
     * @param list<string>  $unsupported
     */
    private function walk(array $node, string $path, array &$unsupported): void
    {
        foreach ($node as $keyword => $value) {
            $keyword = (string) $keyword;
            $here = $path . '/' . self::escape($keyword);
            if (!in_array($keyword, self::SUPPORTED_KEYWORDS, true) && !in_array($keyword, self::ANNOTATIONS, true)) {
                $unsupported[] = $keyword;
                continue;
            }
            switch ($keyword) {
                case 'properties':
                case '$defs':
                case 'definitions':
                    if (!is_array($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be an object.', $this->name, $here));
                    }
                    foreach ($value as $child => $sub) {
                        if (!is_array($sub)) {
                            throw new ConfigurationException(sprintf('Schema "%s": %s/%s must be a schema object.', $this->name, $here, self::escape((string) $child)));
                        }
                        $this->walk($sub, $here . '/' . self::escape((string) $child), $unsupported);
                    }
                    break;
                case 'items':
                case 'additionalProperties':
                    if (is_array($value)) {
                        $this->walk($value, $here, $unsupported);
                    } elseif (!is_bool($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be a schema or a boolean.', $this->name, $here));
                    }
                    break;
                case 'anyOf':
                    if (!is_array($value) || $value === [] || !array_is_list($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be a non-empty list.', $this->name, $here));
                    }
                    foreach ($value as $i => $sub) {
                        if (!is_array($sub)) {
                            throw new ConfigurationException(sprintf('Schema "%s": %s/%d must be a schema object.', $this->name, $here, $i));
                        }
                        $this->walk($sub, $here . '/' . $i, $unsupported);
                    }
                    break;
                case 'required':
                case 'enum':
                    if (!is_array($value) || !array_is_list($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be a list.', $this->name, $here));
                    }
                    break;
                case 'pattern':
                    if (!is_string($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be a string.', $this->name, $here));
                    }
                    $this->compilePattern($value, $here);
                    break;
                case '$ref':
                    if (!is_string($value)) {
                        throw new ConfigurationException(sprintf('Schema "%s": %s must be a string.', $this->name, $here));
                    }
                    $this->resolveRef($value);
                    break;
                case 'type':
                    $types = is_array($value) ? $value : [$value];
                    foreach ($types as $type) {
                        if (!in_array($type, ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'], true)) {
                            throw new ConfigurationException(sprintf('Schema "%s": %s has an unknown type.', $this->name, $here));
                        }
                    }
                    break;
            }
        }
    }

    private function compilePattern(string $pattern, string $path): void
    {
        if (isset($this->patterns[$pattern])) {
            return;
        }
        if (str_contains($pattern, "\x01")) {
            throw new ConfigurationException(sprintf('Schema "%s": pattern at %s contains a control character.', $this->name, $path));
        }
        $regex = "\x01" . $pattern . "\x01u";
        $warning = null;
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $ok = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
        if ($ok === false || $warning !== null) {
            throw new ConfigurationException(sprintf('Schema "%s": pattern at %s is not a valid regular expression.', $this->name, $path));
        }
        $this->patterns[$pattern] = $regex;
    }

    /**
     * @param array<mixed> $node
     */
    private function findStrictProblem(array $node, string $path, int $depth = 0): ?string
    {
        if ($depth > 64) {
            return null;
        }
        $allowed = array_merge(self::SUPPORTED_KEYWORDS, self::ANNOTATIONS, self::STRICT_EXTRA);
        foreach (array_keys($node) as $keyword) {
            if (!in_array((string) $keyword, $allowed, true)) {
                return sprintf('%s: keyword "%s" is not supported in strict mode', $path . '/' . self::escape((string) $keyword), $keyword);
            }
        }
        $types = isset($node['type']) ? (array) $node['type'] : [];
        $isObject = in_array('object', $types, true) || isset($node['properties']);
        if ($isObject) {
            if (($node['additionalProperties'] ?? null) !== false) {
                return sprintf('%s: additionalProperties must be false', $path === '' ? '/' : $path);
            }
            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $required = is_array($node['required'] ?? null) ? $node['required'] : [];
            foreach (array_keys($properties) as $property) {
                if (!in_array((string) $property, $required, true)) {
                    return sprintf('%s/properties/%s: every property must be listed in required', $path, self::escape((string) $property));
                }
            }
            foreach ($properties as $property => $sub) {
                if (is_array($sub) && ($problem = $this->findStrictProblem($sub, $path . '/properties/' . self::escape((string) $property), $depth + 1)) !== null) {
                    return $problem;
                }
            }
        }
        if (isset($node['items']) && is_array($node['items']) && ($problem = $this->findStrictProblem($node['items'], $path . '/items', $depth + 1)) !== null) {
            return $problem;
        }
        foreach (['anyOf'] as $list) {
            foreach ((array) ($node[$list] ?? []) as $i => $sub) {
                if (is_array($sub) && ($problem = $this->findStrictProblem($sub, $path . '/' . $list . '/' . $i, $depth + 1)) !== null) {
                    return $problem;
                }
            }
        }
        foreach (['$defs', 'definitions'] as $map) {
            foreach ((array) ($node[$map] ?? []) as $name => $sub) {
                if (is_array($sub) && ($problem = $this->findStrictProblem($sub, $path . '/' . $map . '/' . self::escape((string) $name), $depth + 1)) !== null) {
                    return $problem;
                }
            }
        }

        return null;
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
