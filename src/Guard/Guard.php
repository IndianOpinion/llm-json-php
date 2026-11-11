<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * Runs a guard profile over parsed, schema-valid output.
 *
 * Order: G3, G4 and G5 on every string and key; G7 on named arrays; G6 on
 * named strings; G10 and G11; G12; then the G8 floors are measured.
 */
final class Guard
{
    /**
     * @param array<mixed> $data
     *
     * @throws ConfigurationException when a custom rule throws
     */
    public function run(array $data, GuardProfile $profile): GuardOutcome
    {
        $original = $data;
        $repairs = [];
        $violations = [];
        $rules = [Rule::TRUNCATED_JSON, Rule::REQUIRED_FIELDS, Rule::INVISIBLE_CHARACTERS, Rule::CONTROL_CHARACTERS, Rule::REPLACEMENT_CHARACTER];

        $singleLine = [];
        foreach ($profile->singleLinePaths() as $path) {
            foreach (Paths::resolve($data, $path) as $segments) {
                $singleLine[Paths::pointer($segments)] = true;
            }
        }
        $data = $this->scan($data, [], $profile, $singleLine, $repairs, $violations);

        if ($profile->continuationPaths() !== []) {
            $rules[] = Rule::CONTINUATION_ITEMS;
        }
        foreach ($profile->continuationPaths() as $path => $action) {
            foreach (Paths::resolve($data, $path) as $segments) {
                $list = Paths::get($data, $segments);
                if (!is_array($list) || !array_is_list($list)) {
                    continue;
                }
                $keep = [];
                foreach ($list as $i => $item) {
                    if ($i > 0 && is_string($item) && TextRules::startsLowerCase($item)) {
                        $pointer = Paths::pointer(array_merge($segments, [$i]));
                        if ($action === Action::Repair) {
                            $repairs[] = new Repair(Rule::CONTINUATION_ITEMS, $pointer, 'removed an item that starts in lower case', null, TextRules::wordCount($item), 0, true);
                            continue;
                        }
                        $violations[] = new Violation(Rule::CONTINUATION_ITEMS, $pointer, 'item starts in lower case: the tail of a sentence cut off by a quote');
                    }
                    $keep[] = $item;
                }
                Paths::set($data, $segments, $keep);
            }
        }

        if ($profile->sentenceEndPaths() !== []) {
            $rules[] = Rule::UNFINISHED_PROSE;
        }
        foreach ($profile->sentenceEndPaths() as $path => $action) {
            $emptied = [];
            foreach (Paths::resolve($data, $path) as $segments) {
                $value = Paths::get($data, $segments);
                if (is_string($value)) {
                    $fixed = $this->sentence($value, Paths::pointer($segments), $action, $profile, $repairs, $violations);
                    Paths::set($data, $segments, $fixed);
                    $parent = array_slice($segments, 0, -1);
                    $parentValue = Paths::get($data, $parent);
                    if ($fixed === '' && $value !== '' && is_int(end($segments)) && is_array($parentValue) && array_is_list($parentValue)) {
                        $emptied[Paths::pointer($parent)] = $parent;
                    }
                } elseif (is_array($value) && array_is_list($value)) {
                    $changed = false;
                    foreach ($value as $i => $item) {
                        if (is_string($item)) {
                            $fixed = $this->sentence($item, Paths::pointer(array_merge($segments, [$i])), $action, $profile, $repairs, $violations);
                            $changed = $changed || ($fixed === '' && $item !== '');
                            $value[$i] = $fixed;
                        }
                    }
                    Paths::set($data, $segments, $value);
                    if ($changed) {
                        $emptied[Paths::pointer($segments)] = $segments;
                    }
                }
            }
            foreach ($emptied as $parent) {
                $this->removeEmptyItems($data, $parent);
            }
        }

        if ($profile->scriptShares() !== []) {
            $rules[] = Rule::SCRIPT_SHARE;
        }
        foreach ($profile->scriptShares() as [$path, $script, $min]) {
            foreach (Paths::resolve($data, $path) as $segments) {
                $share = TextRules::scriptShare(self::text(Paths::get($data, $segments)), $script);
                if ($share !== null && $share < $min) {
                    $violations[] = new Violation(Rule::SCRIPT_SHARE, Paths::pointer($segments), sprintf('%d%% of letters are %s, below the %d%% floor', (int) round($share * 100), $script, (int) round($min * 100)));
                }
            }
        }

        if ($profile->mixedScriptWords()) {
            $rules[] = Rule::MIXED_SCRIPT_WORDS;
            $this->eachString($data, [], function (string $s, array $segments) use (&$violations): void {
                if (TextRules::hasMixedScriptWord($s)) {
                    $violations[] = new Violation(Rule::MIXED_SCRIPT_WORDS, Paths::pointer($segments), 'a word mixes Latin with Cyrillic or Greek letters');
                }
            });
        }

        foreach ($profile->customRules() as $name => $rule) {
            $rules[] = Rule::CUSTOM . ':' . $name;
            try {
                $found = $rule($data);
                if (!is_iterable($found)) {
                    throw new \UnexpectedValueException(sprintf('a rule must return an iterable of violations, got %s', get_debug_type($found)));
                }
                foreach ($found as $item) {
                    if ($item instanceof Violation) {
                        $violations[] = $item;
                    } elseif (is_array($item) && is_string($item[0] ?? null) && is_string($item[1] ?? null)) {
                        $violations[] = new Violation(Rule::CUSTOM . ':' . $name, $item[0], $item[1]);
                    } else {
                        throw new \UnexpectedValueException('a rule must return Violation objects or [path, message] pairs');
                    }
                }
            } catch (\Throwable $e) {
                throw new ConfigurationException(sprintf('Custom guard rule "%s" threw: %s', $name, $e->getMessage()), 0, $e);
            }
        }

        $shortfall = false;
        $floorWords = 0;
        if ($profile->wordFloors() !== []) {
            $rules[] = Rule::WORD_FLOOR;
        }
        foreach ($profile->wordFloors() as $path => $min) {
            $after = 0;
            foreach (Paths::resolve($data, $path) as $segments) {
                $after += TextRules::wordCount(self::text(Paths::get($data, $segments)));
            }
            $before = 0;
            foreach (Paths::resolve($original, $path) as $segments) {
                $before += TextRules::wordCount(self::text(Paths::get($original, $segments)));
            }
            $floorWords += $after;
            if ($after < $min && $before > $after) {
                $shortfall = true;
            }
        }

        return new GuardOutcome($data, $repairs, $violations, $rules, $shortfall, $floorWords);
    }

    /**
     * @param list<Repair>    $repairs
     * @param list<Violation> $violations
     */
    private function sentence(string $value, string $pointer, Action $action, GuardProfile $profile, array &$repairs, array &$violations): string
    {
        if (TextRules::endsSentence($value)) {
            return $value;
        }
        if ($action === Action::Reject) {
            $violations[] = new Violation(Rule::UNFINISHED_PROSE, $pointer, 'text does not end in sentence-final punctuation');

            return $value;
        }
        $fixed = TextRules::cutToLastSentence($value, $profile->abbreviations());
        $repairs[] = new Repair(
            Rule::UNFINISHED_PROSE,
            $pointer,
            $fixed === '' ? 'no complete sentence, value emptied' : 'cut back to the last complete sentence',
            null,
            TextRules::wordCount($value),
            TextRules::wordCount($fixed),
        );

        return $fixed;
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $segments
     */
    private function removeEmptyItems(array &$data, array $segments): void
    {
        $list = Paths::get($data, $segments);
        if (is_array($list) && array_is_list($list)) {
            Paths::set($data, $segments, array_values(array_filter($list, static fn (mixed $item): bool => $item !== '')));
        }
    }

    /**
     * G3, G4, G5 on every key and string value.
     *
     * @param array<mixed>        $node
     * @param list<string|int>    $at
     * @param array<string, bool> $singleLine
     * @param list<Repair>        $repairs
     * @param list<Violation>     $violations
     *
     * @return array<mixed>
     */
    private function scan(array $node, array $at, GuardProfile $profile, array $singleLine, array &$repairs, array &$violations): array
    {
        foreach ($node as $key => $value) {
            $segments = array_merge($at, [$key]);
            if (is_string($key)) {
                $this->checkKey($key, Paths::pointer($segments), $profile, $violations);
            }
            if (is_array($value)) {
                $node[$key] = $this->scan($value, $segments, $profile, $singleLine, $repairs, $violations);
            } elseif (is_string($value)) {
                $node[$key] = $this->scanString($value, Paths::pointer($segments), $profile, isset($singleLine[Paths::pointer($segments)]), $repairs, $violations);
            }
        }

        return $node;
    }

    /**
     * @param list<Violation> $violations
     */
    private function checkKey(string $key, string $pointer, GuardProfile $profile, array &$violations): void
    {
        if (TextRules::invisibleOffenders($key, $profile->joiningScripts()) !== []) {
            $violations[] = new Violation(Rule::INVISIBLE_CHARACTERS, $pointer, 'object key contains an invisible character');
        }
        if (preg_match('/' . TextRules::CONTROL . '|[\t\r\n]/u', $key) === 1) {
            $violations[] = new Violation(Rule::CONTROL_CHARACTERS, $pointer, 'object key contains a control character');
        }
        if (str_contains($key, "\u{FFFD}")) {
            $violations[] = new Violation(Rule::REPLACEMENT_CHARACTER, $pointer, 'object key contains U+FFFD');
        }
    }

    /**
     * @param list<Repair>    $repairs
     * @param list<Violation> $violations
     */
    private function scanString(string $value, string $pointer, GuardProfile $profile, bool $singleLine, array &$repairs, array &$violations): string
    {
        $offenders = TextRules::invisibleOffenders($value, $profile->joiningScripts());
        if ($offenders !== []) {
            if ($profile->invisibleAction() === Action::Repair) {
                $value = TextRules::removeAt($value, $offenders);
                $repairs[] = new Repair(Rule::INVISIBLE_CHARACTERS, $pointer, 'removed invisible characters', count($offenders));
            } else {
                $violations[] = new Violation(Rule::INVISIBLE_CHARACTERS, $pointer, sprintf('%d invisible character(s), first at U+%04X', count($offenders), \IndianOpinion\LlmJson\Internal\Text::ord(substr($value, $offenders[0][0], $offenders[0][1]))));
            }
        }

        $controls = (int) preg_match_all('/' . TextRules::CONTROL . '/u', $value);
        $breaks = $singleLine ? (int) preg_match_all('/[\t\r\n]/', $value) : 0;
        if ($controls + $breaks > 0) {
            if ($profile->controlAction() === Action::Repair) {
                $value = (string) preg_replace('/' . TextRules::CONTROL . '/u', '', $value);
                if ($breaks > 0) {
                    $value = trim((string) preg_replace('/[\t\r\n]+/', ' ', $value));
                }
                $repairs[] = new Repair(Rule::CONTROL_CHARACTERS, $pointer, 'removed control characters', $controls + $breaks);
            } else {
                $violations[] = new Violation(Rule::CONTROL_CHARACTERS, $pointer, $controls > 0 ? 'contains a control character' : 'single-line field contains a tab or line break');
            }
        }

        if (str_contains($value, "\u{FFFD}")) {
            $violations[] = new Violation(Rule::REPLACEMENT_CHARACTER, $pointer, 'contains U+FFFD: text was lost to a decoding failure upstream');
        }

        return $value;
    }

    /**
     * @param array<mixed>                           $node
     * @param list<string|int>                       $at
     * @param callable(string, list<string|int>): void $fn
     */
    private function eachString(array $node, array $at, callable $fn): void
    {
        foreach ($node as $key => $value) {
            $segments = array_merge($at, [$key]);
            if (is_array($value)) {
                $this->eachString($value, $segments, $fn);
            } elseif (is_string($value)) {
                $fn($value, $segments);
            }
        }
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return implode("\n", array_filter($value, 'is_string'));
        }

        return '';
    }
}
