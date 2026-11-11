<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * Authentication failed (401, or 403 without a billing pattern).
 */
final class AuthenticationException extends HttpException
{
}
