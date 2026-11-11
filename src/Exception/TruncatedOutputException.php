<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * The provider reported finish reason length: the output was cut off.
 */
final class TruncatedOutputException extends AttemptException
{
}
