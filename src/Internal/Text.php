<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

/**
 * UTF-8 helpers that need neither mbstring nor intl.
 *
 * @internal
 */
final class Text
{
    public static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    /** Length in code points. Assumes valid UTF-8. */
    public static function length(string $s): int
    {
        return strlen($s) - (int) preg_match_all('/[\x80-\xBF]/', $s);
    }

    /** The first $max code points; invalid UTF-8 is cut by bytes and cleaned. */
    public static function cut(string $s, int $max): string
    {
        if (!self::isUtf8($s)) {
            $s = (string) preg_replace('/[\x80-\xFF]/', '?', $s);
        }
        if (strlen($s) <= $max) {
            return $s;
        }
        if (preg_match('/^.{0,' . $max . '}/us', $s, $m) === 1) {
            return $m[0];
        }

        return substr($s, 0, $max);
    }

    /** The code point that ends just before byte $offset, or '' at the start. */
    public static function charBefore(string $s, int $offset): string
    {
        if ($offset <= 0) {
            return '';
        }
        $start = $offset - 1;
        while ($start > 0 && (ord($s[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return substr($s, $start, $offset - $start);
    }

    /** The code point that starts at byte $offset, or '' at the end. */
    public static function charAt(string $s, int $offset): string
    {
        $len = strlen($s);
        if ($offset >= $len) {
            return '';
        }
        $end = $offset + 1;
        while ($end < $len && (ord($s[$end]) & 0xC0) === 0x80) {
            $end++;
        }

        return substr($s, $offset, $end - $offset);
    }

    /** Code point value of a single UTF-8 character. */
    public static function ord(string $char): int
    {
        $bytes = array_values((array) unpack('C*', $char));
        $first = (int) ($bytes[0] ?? 0);
        $n = count($bytes);
        if ($n === 1) {
            return $first;
        }
        $code = $first & (0xFF >> ($n + 1));
        for ($i = 1; $i < $n; $i++) {
            $code = ($code << 6) | ((int) $bytes[$i] & 0x3F);
        }

        return $code;
    }
}
