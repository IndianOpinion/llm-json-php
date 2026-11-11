<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

use IndianOpinion\LlmJson\Internal\Text;

/**
 * Text checks used by the guard. Public so callers can reuse them; every
 * function is linear in the input and uses no nested quantifiers.
 */
final class TextRules
{
    /** G3 characters that are always rejected. */
    public const INVISIBLE = '[\x{200B}\x{2060}\x{FEFF}\x{180E}\x{00AD}\x{034F}\x{115F}\x{1160}\x{3164}\x{FFA0}'
        . '\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{E0000}-\x{E007F}]';

    /** G4 control characters (tab, line feed and carriage return are allowed). */
    public const CONTROL = '[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]';

    /** Sentence-final punctuation for G6. */
    public const TERMINATORS = '.!?\x{2026}\x{0964}\x{0965}\x{3002}\x{FF01}\x{FF1F}\x{061F}';

    /** Closing marks that may follow a terminator. */
    public const CLOSERS = '"\'\x{201C}\x{201D}\x{2018}\x{2019}\x{00BB})\]';

    /** Scripts written without spaces, counted one word per character. */
    private const SPACELESS = '[\p{Han}\p{Hiragana}\p{Katakana}]';

    private function __construct()
    {
    }

    /**
     * Byte offsets and lengths of characters G3 rejects, joiners included
     * when they are not between letters of one joining script or inside an
     * emoji sequence.
     *
     * @param list<string> $joiningScripts
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function invisibleOffenders(string $s, array $joiningScripts): array
    {
        $found = [];
        if (preg_match_all('/' . self::INVISIBLE . '/u', $s, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$char, $offset]) {
                $found[] = [(int) $offset, strlen($char)];
            }
        }
        if (str_contains($s, "\u{200C}") || str_contains($s, "\u{200D}")) {
            preg_match_all('/[\x{200C}\x{200D}]/u', $s, $j, PREG_OFFSET_CAPTURE);
            foreach ($j[0] as [$char, $offset]) {
                $offset = (int) $offset;
                $before = Text::charBefore($s, $offset);
                $after = Text::charAt($s, $offset + 3);
                if (!self::joinerAllowed($char, $before, $after, $joiningScripts)) {
                    $found[] = [$offset, 3];
                }
            }
            usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        return $found;
    }

    /**
     * @param list<string> $joiningScripts
     */
    public static function joinerAllowed(string $joiner, string $before, string $after, array $joiningScripts): bool
    {
        if ($before === '' || $after === '') {
            return false;
        }
        if ($joiner === "\u{200D}" && self::isEmojiPart($before) && self::isEmojiPart($after)) {
            return true;
        }
        if (preg_match('/^[\p{L}\p{M}]$/u', $before) !== 1 || preg_match('/^[\p{L}\p{M}]$/u', $after) !== 1) {
            return false;
        }
        foreach ($joiningScripts as $script) {
            $re = '/^\p{' . $script . '}$/u';
            if (preg_match($re, $before) === 1 && preg_match($re, $after) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isEmojiPart(string $char): bool
    {
        $cp = Text::ord($char);

        return ($cp >= 0x1F000 && $cp <= 0x1FAFF)
            || ($cp >= 0x2600 && $cp <= 0x27BF)
            || ($cp >= 0x2300 && $cp <= 0x23FF)
            || ($cp >= 0x2B00 && $cp <= 0x2BFF)
            || $cp === 0xFE0F || $cp === 0x20E3;
    }

    /**
     * Remove characters at the given offsets.
     *
     * @param list<array{0: int, 1: int}> $offenders sorted by offset
     */
    public static function removeAt(string $s, array $offenders): string
    {
        $out = '';
        $pos = 0;
        foreach ($offenders as [$offset, $length]) {
            if ($offset < $pos) {
                continue;
            }
            $out .= substr($s, $pos, $offset - $pos);
            $pos = $offset + $length;
        }

        return $out . substr($s, $pos);
    }

    /** Markup stripped, entities decoded, trailing space trimmed. */
    public static function plain(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/[\s\x{00A0}]+$/u', '', $s);
    }

    /** G6: does the value end a sentence? An empty value passes. */
    public static function endsSentence(string $s): bool
    {
        $plain = self::plain($s);
        if ($plain === '') {
            return true;
        }

        return preg_match('/[' . self::TERMINATORS . '][' . self::CLOSERS . ']*$/u', $plain) === 1;
    }

    /**
     * G6 repair: the value cut back to the end of its last complete
     * sentence, or '' when it has none. Never cuts inside a sentence: a
     * full stop after an abbreviation or an initial, and a terminator
     * followed by a lower-case word, are not sentence ends.
     *
     * @param list<string> $abbreviations lower case, without the final full stop
     */
    public static function cutToLastSentence(string $s, array $abbreviations): string
    {
        $pattern = '/[' . self::TERMINATORS . ']+[' . self::CLOSERS . ']*(?:<\/[A-Za-z][A-Za-z0-9]*>)*(?=[\s\x{00A0}]|$)/u';
        if (preg_match_all($pattern, $s, $m, PREG_OFFSET_CAPTURE) < 1) {
            return '';
        }
        $lookup = array_flip($abbreviations);
        for ($i = count($m[0]) - 1; $i >= 0; $i--) {
            [$match, $offset] = $m[0][$i];
            if (self::isAbbreviation($s, (int) $offset, $match, $lookup)) {
                continue;
            }
            // A sentence never goes on in lower case: in 'He said "Stop."
            // and left', the quoted full stop is not where the sentence ends.
            if (preg_match('/\G[\s\x{00A0}]*(?:<[^>]*>[\s\x{00A0}]*)*\p{Ll}/u', $s, $next, 0, (int) $offset + strlen($match)) === 1) {
                continue;
            }

            return rtrim(substr($s, 0, (int) $offset + strlen($match)));
        }

        return '';
    }

    /**
     * @param array<string, int> $lookup
     */
    private static function isAbbreviation(string $s, int $offset, string $match, array $lookup): bool
    {
        if ($match[0] !== '.' || str_starts_with($match, '..')) {
            return false;
        }
        if (preg_match('/([\p{L}\p{N}.]+)$/u', substr($s, 0, $offset), $w) !== 1) {
            return false;
        }
        $token = $w[1];
        if (preg_match('/^\p{Lu}$/u', $token) === 1) {
            return true;
        }
        if (preg_match('/^(?:\p{L}\.)+\p{L}$/u', $token) === 1) {
            return true;
        }
        $lower = strtolower($token);
        if (isset($lookup[$lower])) {
            return true;
        }
        $last = strrchr($lower, '.');

        return $last !== false && isset($lookup[substr($last, 1)]);
    }

    /** G7: does the item start with a lower-case letter? */
    public static function startsLowerCase(string $s): bool
    {
        return preg_match('/^[\s"\'\x{201C}\x{2018}(\[]*\p{Ll}/u', $s) === 1;
    }

    /**
     * Words: runs of letters, marks and digits; each Han or kana character
     * counts as one word.
     */
    public static function wordCount(string $s): int
    {
        $spaceless = (int) preg_match_all('/' . self::SPACELESS . '/u', $s);
        if ($spaceless > 0) {
            $s = (string) preg_replace('/' . self::SPACELESS . '/u', ' ', $s);
        }

        return $spaceless + (int) preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $s);
    }

    /** Share of letters in the script, or null under 50 letters. */
    public static function scriptShare(string $s, string $script): ?float
    {
        $letters = (int) preg_match_all('/\p{L}/u', $s);
        if ($letters < 50) {
            return null;
        }

        return (int) preg_match_all('/\p{' . $script . '}/u', $s) / $letters;
    }

    /** G11: first word that mixes Latin with Cyrillic or Greek letters. */
    public static function hasMixedScriptWord(string $s): bool
    {
        if (preg_match('/\p{Latin}/u', $s) !== 1 || preg_match('/[\p{Cyrillic}\p{Greek}]/u', $s) !== 1) {
            return false;
        }
        preg_match_all('/[\p{L}\p{M}]+/u', $s, $m);
        foreach ($m[0] as $word) {
            if (preg_match('/\p{Latin}/u', $word) === 1 && preg_match('/[\p{Cyrillic}\p{Greek}]/u', $word) === 1) {
                return true;
            }
        }

        return false;
    }
}
