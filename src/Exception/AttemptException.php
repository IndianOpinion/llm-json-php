<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * Base class for the per-attempt errors recorded on each Attempt. These do
 * not reach the caller from Client::complete(); they appear on attempts and
 * as the previous exception of a chain failure.
 */
abstract class AttemptException extends LlmJsonException
{
}
