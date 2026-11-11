<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The HTTP response could not be read as a completion: empty, not JSON, no choices, tool calls, or a provider error inside a 200.
 */
final class InvalidResponseException extends AttemptException
{
}
