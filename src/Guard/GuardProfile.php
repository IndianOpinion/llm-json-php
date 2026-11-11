<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Schema;

/**
 * Guard settings for one purpose. Immutable; every with* method returns a
 * copy.
 *
 * GuardProfile::default() applies G1 to G5 with reject and nothing else.
 * Rules that name field paths need a profile made with forSchema(), so a
 * path that is not in the schema fails at once.
 */
final class GuardProfile
{
    /** @var list<string> */
    public const DEFAULT_JOINING_SCRIPTS = [
        'Devanagari', 'Bengali', 'Gurmukhi', 'Gujarati', 'Oriya', 'Tamil',
        'Telugu', 'Kannada', 'Malayalam', 'Sinhala', 'Arabic', 'Syriac',
    ];

    /** @var list<string> English abbreviations whose full stop does not end a sentence. */
    public const ENGLISH_ABBREVIATIONS = [
        'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr', 'st', 'mt', 'ft', 'gen', 'col', 'capt', 'lt',
        'sgt', 'rev', 'hon', 'gov', 'sen', 'rep', 'pres', 'supt', 'insp', 'vs', 'v', 'no', 'nos',
        'vol', 'vols', 'fig', 'figs', 'pp', 'p', 'approx', 'est', 'dept', 'inc', 'ltd', 'co', 'corp',
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec',
        'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
        'rs', 'bn', 'mn', 'cr', 'pct', 'cf', 'al', 'e.g', 'i.e', 'a.m', 'p.m', 'u.s', 'u.k', 'u.n',
    ];

    private ?Schema $schema = null;
    private Action $invisible = Action::Reject;
    private Action $control = Action::Reject;
    /** @var list<string> */
    private array $singleLine = [];
    /** @var array<string, Action> */
    private array $sentenceEnd = [];
    /** @var array<string, Action> */
    private array $continuation = [];
    /** @var array<string, int> */
    private array $floors = [];
    /** @var list<array{0: string, 1: string, 2: float}> */
    private array $scriptShares = [];
    private bool $mixedScript = false;
    /** @var array<string, callable(array<mixed>): iterable<mixed>> */
    private array $custom = [];
    private bool $quoteInstruction = false;
    /** @var list<string> */
    private array $joiningScripts = self::DEFAULT_JOINING_SCRIPTS;
    /** @var list<string> */
    private array $abbreviations = self::ENGLISH_ABBREVIATIONS;

    private function __construct()
    {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function forSchema(Schema $schema): self
    {
        $profile = new self();
        $profile->schema = $schema;

        return $profile;
    }

    public function schema(): ?Schema
    {
        return $this->schema;
    }

    /** G3. Repair removes the characters and records how many. */
    public function withInvisibleCharacters(Action $action): self
    {
        $clone = clone $this;
        $clone->invisible = $action;

        return $clone;
    }

    /**
     * G4. Repair removes the characters. Single-line paths also forbid tab
     * and line breaks; repair turns those into a space.
     *
     * @param list<string> $singleLinePaths
     */
    public function withControlCharacters(Action $action, array $singleLinePaths = []): self
    {
        foreach ($singleLinePaths as $path) {
            $this->checkPath($path);
        }
        $clone = clone $this;
        $clone->control = $action;
        $clone->singleLine = array_values($singleLinePaths);

        return $clone;
    }

    /**
     * G6. Each named string (or each string in a named array) must end a
     * sentence. Repair cuts back to the last complete sentence.
     *
     * @param list<string> $paths
     */
    public function withSentenceEnd(array $paths, Action $action = Action::Reject): self
    {
        $clone = clone $this;
        foreach ($paths as $path) {
            $this->checkPath($path);
            $clone->sentenceEnd[$path] = $action;
        }

        return $clone;
    }

    /**
     * G7. In each named array of strings, an item after the first that
     * starts with a lower-case letter is rejected, or removed on repair.
     *
     * @param list<string> $paths
     */
    public function withContinuationItems(array $paths, Action $action = Action::Reject): self
    {
        $clone = clone $this;
        foreach ($paths as $path) {
            $this->checkPath($path);
            $clone->continuation[$path] = $action;
        }

        return $clone;
    }

    /** G8. Minimum words at a path after repair (across items for an array). */
    public function withWordFloor(string $path, int $minWords): self
    {
        $this->checkPath($path);
        if ($minWords < 1) {
            throw new ConfigurationException('A word floor must be at least 1.');
        }
        $clone = clone $this;
        $clone->floors[$path] = $minWords;

        return $clone;
    }

    /** G9. Ask the model not to type straight double quotes inside text values. */
    public function withQuoteInstruction(bool $on = true): self
    {
        $clone = clone $this;
        $clone->quoteInstruction = $on;

        return $clone;
    }

    /**
     * G10. At least $minShare (0 to 1) of the letters at the path must be in
     * the script, once there are 50 letters. Script names are Unicode script
     * names as PCRE knows them, for example Devanagari or Tamil.
     */
    public function withScriptShare(string $path, string $script, float $minShare): self
    {
        $this->checkPath($path);
        if (preg_match('/^[A-Z][A-Za-z_]{1,40}$/', $script) !== 1 || !self::scriptKnown($script)) {
            throw new ConfigurationException(sprintf('"%s" is not a Unicode script name PCRE knows.', $script));
        }
        if ($minShare <= 0 || $minShare > 1) {
            throw new ConfigurationException('A script share must be above 0 and at most 1.');
        }
        $clone = clone $this;
        $clone->scriptShares[] = [$path, $script, $minShare];

        return $clone;
    }

    /** G11. Reject a word that mixes Latin letters with Cyrillic or Greek. */
    public function withMixedScriptWords(bool $on = true): self
    {
        $clone = clone $this;
        $clone->mixedScript = $on;

        return $clone;
    }

    /**
     * G12. The callable receives the data and returns violations, each a
     * Violation or a [path, message] pair. A callable that throws is a bug
     * in the caller's code and reaches the caller as a configuration error.
     *
     * @param callable(array<mixed>): iterable<mixed> $rule
     */
    public function withCustomRule(string $name, callable $rule): self
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) !== 1) {
            throw new ConfigurationException('Custom rule names are 1 to 64 letters, digits, underscores or hyphens.');
        }
        $clone = clone $this;
        $clone->custom[$name] = $rule;

        return $clone;
    }

    /**
     * Scripts whose letters may be joined by U+200C and U+200D.
     *
     * @param list<string> $scripts
     */
    public function withJoiningScripts(array $scripts): self
    {
        foreach ($scripts as $script) {
            if (preg_match('/^[A-Z][A-Za-z_]{1,40}$/', $script) !== 1 || !self::scriptKnown($script)) {
                throw new ConfigurationException(sprintf('"%s" is not a Unicode script name PCRE knows.', $script));
            }
        }
        $clone = clone $this;
        $clone->joiningScripts = array_values($scripts);

        return $clone;
    }

    /**
     * Words whose full stop does not end a sentence, without the final full
     * stop and in any case (for example "Dr" or "e.g").
     *
     * @param list<string> $abbreviations
     */
    public function withAbbreviations(array $abbreviations, bool $replace = false): self
    {
        $clean = [];
        foreach ($abbreviations as $abbreviation) {
            $abbreviation = strtolower(rtrim(trim($abbreviation), '.'));
            if ($abbreviation === '') {
                throw new ConfigurationException('Abbreviations must not be empty.');
            }
            $clean[] = $abbreviation;
        }
        $clone = clone $this;
        $clone->abbreviations = array_values(array_unique($replace ? $clean : array_merge($this->abbreviations, $clean)));

        return $clone;
    }

    /** Throws when a named path is not in the schema. */
    public function assertCompatibleWith(Schema $schema): void
    {
        foreach ($this->paths() as $path) {
            Paths::assertInSchema($path, $schema);
        }
    }

    /** @return list<string> */
    public function paths(): array
    {
        $paths = array_merge(
            $this->singleLine,
            array_keys($this->sentenceEnd),
            array_keys($this->continuation),
            array_keys($this->floors),
            array_map(static fn (array $s): string => $s[0], $this->scriptShares),
        );

        return array_values(array_unique(array_map('strval', $paths)));
    }

    public function invisibleAction(): Action
    {
        return $this->invisible;
    }

    public function controlAction(): Action
    {
        return $this->control;
    }

    /** @return list<string> */
    public function singleLinePaths(): array
    {
        return $this->singleLine;
    }

    /** @return array<string, Action> */
    public function sentenceEndPaths(): array
    {
        return $this->sentenceEnd;
    }

    /** @return array<string, Action> */
    public function continuationPaths(): array
    {
        return $this->continuation;
    }

    /** @return array<string, int> */
    public function wordFloors(): array
    {
        return $this->floors;
    }

    /** @return list<array{0: string, 1: string, 2: float}> */
    public function scriptShares(): array
    {
        return $this->scriptShares;
    }

    public function mixedScriptWords(): bool
    {
        return $this->mixedScript;
    }

    /** @return array<string, callable(array<mixed>): iterable<mixed>> */
    public function customRules(): array
    {
        return $this->custom;
    }

    public function quoteInstruction(): bool
    {
        return $this->quoteInstruction;
    }

    /** @return list<string> */
    public function joiningScripts(): array
    {
        return $this->joiningScripts;
    }

    /** @return list<string> */
    public function abbreviations(): array
    {
        return $this->abbreviations;
    }

    private function checkPath(string $path): void
    {
        Paths::parse($path);
        if ($this->schema === null) {
            throw new ConfigurationException('Rules with field paths need a profile made with GuardProfile::forSchema(), so the paths can be checked.');
        }
        Paths::assertInSchema($path, $this->schema);
    }

    private static function scriptKnown(string $script): bool
    {
        [$result] = \IndianOpinion\LlmJson\Internal\ErrorTrap::run(static fn () => preg_match('/\p{' . $script . '}/u', ''));

        return $result !== false;
    }
}
