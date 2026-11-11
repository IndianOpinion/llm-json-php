<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * What one attempt came to. Each attempt has exactly one outcome.
 */
enum Outcome: string
{
    case Success = 'success';
    case TransportError = 'transport_error';
    case TlsVerificationFailed = 'tls_verification_failed';
    case Redirect = 'redirect';
    case InvalidResponse = 'invalid_response';
    case ProviderError = 'provider_error';
    case Truncated = 'truncated';
    case Malformed = 'malformed';
    case SchemaViolation = 'schema_violation';
    case GuardRejected = 'guard_rejected';
    case Refusal = 'refusal';
    case ContentFilter = 'content_filter';
    case RateLimited = 'rate_limited';
    case AuthenticationFailed = 'authentication_failed';
    case CreditExhausted = 'credit_exhausted';
    case RequestRejected = 'request_rejected';
    case ServerError = 'server_error';
    case HttpError = 'http_error';

    /** May the same route be tried again (case 83)? */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TransportError, self::InvalidResponse, self::ProviderError, self::Truncated,
            self::Malformed, self::SchemaViolation, self::GuardRejected, self::RateLimited,
            self::ServerError => true,
            default => false,
        };
    }

    /**
     * Belongs to the provider account or its setup, not the model: the rest
     * of the provider is skipped for this call and no cooldown is counted.
     */
    public function isAccountLevel(): bool
    {
        return match ($this) {
            self::AuthenticationFailed, self::CreditExhausted, self::Redirect => true,
            default => false,
        };
    }

    /** Does a route whose last attempt ended like this count towards cooldown? */
    public function countsTowardsCooldown(): bool
    {
        return match ($this) {
            self::Success, self::AuthenticationFailed, self::CreditExhausted, self::Redirect,
            self::Refusal, self::ContentFilter => false,
            default => true,
        };
    }
}
