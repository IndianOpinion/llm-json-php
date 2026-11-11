<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * How the request asks for JSON.
 */
enum OutputMode: string
{
    /** Strict where the provider supports it and the schema allows it, else JSON mode, else prompt. */
    case Auto = 'auto';
    /** response_format json_schema with strict true. */
    case Strict = 'strict';
    /** response_format json_object, schema in the system message. */
    case Json = 'json';
    /** No response_format, schema in the system message. */
    case Prompt = 'prompt';
}
