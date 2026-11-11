<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * Rate limited (429 without a credit pattern).
 */
final class RateLimitedException extends HttpException
{
}
