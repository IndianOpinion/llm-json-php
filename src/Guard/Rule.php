<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

/**
 * Guard rule identifiers, as they appear in reports and exceptions.
 */
final class Rule
{
    public const TRUNCATED_JSON = 'G1';
    public const REQUIRED_FIELDS = 'G2';
    public const INVISIBLE_CHARACTERS = 'G3';
    public const CONTROL_CHARACTERS = 'G4';
    public const REPLACEMENT_CHARACTER = 'G5';
    public const UNFINISHED_PROSE = 'G6';
    public const CONTINUATION_ITEMS = 'G7';
    public const WORD_FLOOR = 'G8';
    public const QUOTE_INSTRUCTION = 'G9';
    public const SCRIPT_SHARE = 'G10';
    public const MIXED_SCRIPT_WORDS = 'G11';
    public const CUSTOM = 'G12';

    private function __construct()
    {
    }
}
