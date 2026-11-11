<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * Credit exhausted on the provider (402, or a credit pattern in the body).
 */
final class CreditExhaustedAttemptException extends HttpException
{
}
