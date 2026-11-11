<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * A setup mistake: bad provider, route, schema, guard profile, request or
 * store. Thrown before any network traffic where possible.
 */
final class ConfigurationException extends LlmJsonException
{
}
