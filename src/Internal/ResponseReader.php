<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\ContentFilterException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedAttemptException;
use IndianOpinion\LlmJson\Exception\HttpException;
use IndianOpinion\LlmJson\Exception\InvalidResponseException;
use IndianOpinion\LlmJson\Exception\RateLimitedException;
use IndianOpinion\LlmJson\Exception\RefusalException;
use IndianOpinion\LlmJson\Exception\TruncatedOutputException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Transport\HttpResponse;
use IndianOpinion\LlmJson\Usage;

/**
 * Classifies an HTTP response into exactly one outcome (PRD 4.5 and 4.6).
 *
 * @internal
 */
final class ResponseReader
{
    public const ERROR_EXCERPT = 300;
    public const REFUSAL_EXCERPT = 500;

    private const REQUEST_ID_HEADERS = ['x-request-id', 'request-id', 'x-generation-id', 'cf-ray'];
    private const FORMAT_HINTS = ['response_format', 'json_schema', 'structured output', 'structured_output', 'structured-output'];

    public function read(HttpResponse $response, Provider $provider, float $now, #[\SensitiveParameter] string $apiKeyForRedaction = ''): Classified
    {
        $status = $response->status;
        $body = trim($response->body);
        $requestId = null;
        foreach (self::REQUEST_ID_HEADERS as $header) {
            $value = $response->header($header);
            if ($value !== null && $value !== '') {
                $requestId = Text::cut($value, 200);
                break;
            }
        }
        $secrets = $apiKeyForRedaction === '' ? [] : [$apiKeyForRedaction];
        $excerpt = fn (): string => Redactor::excerpt($body, self::ERROR_EXCERPT, $secrets);
        $decoded = ($body !== '' && Text::isUtf8($body)) ? Json::decode($body, 512) : new DecodeFailure();
        $json = is_array($decoded) ? $decoded : null;
        $lower = strtolower($body);

        if ($status >= 300 && $status < 400) {
            return new Classified(Outcome::Redirect, new HttpException(sprintf('HTTP %d redirect: the base URL is wrong or has moved. Redirects are never followed.', $status), $status, $excerpt()), requestId: $requestId, errorExcerpt: $excerpt());
        }

        if ($status >= 200 && $status < 300) {
            return $this->readSuccess($status, $body, $json, $lower, $provider, $requestId, $secrets);
        }

        $credit = $this->isCredit($lower, $json, $provider);
        $retryAfter = $this->retryAfter($response->header('retry-after'), $now);
        $make = fn (Outcome $outcome, HttpException $error, bool $formatRejected = false): Classified => new Classified(
            $outcome,
            $error,
            requestId: $requestId,
            errorExcerpt: $excerpt(),
            retryAfter: $retryAfter,
            formatRejected: $formatRejected,
            usage: $this->usage($json),
        );

        if ($status === 402 || (in_array($status, [403, 429], true) && $credit)) {
            return $make(Outcome::CreditExhausted, new CreditExhaustedAttemptException(sprintf('HTTP %d: provider credit exhausted.', $status), $status, $excerpt()));
        }
        if ($status === 401 || $status === 403) {
            return $make(Outcome::AuthenticationFailed, new AuthenticationException(sprintf('HTTP %d: authentication failed.', $status), $status, $excerpt()));
        }
        if ($status === 429) {
            return $make(Outcome::RateLimited, new RateLimitedException('HTTP 429: rate limited.', $status, $excerpt()));
        }
        if (in_array($status, [400, 404, 409, 413, 422], true)) {
            $formatRejected = false;
            if ($status === 400 || $status === 422) {
                foreach (self::FORMAT_HINTS as $hint) {
                    if (str_contains($lower, $hint)) {
                        $formatRejected = true;
                        break;
                    }
                }
            }

            return $make(Outcome::RequestRejected, new HttpException(sprintf('HTTP %d: request rejected.', $status), $status, $excerpt()), $formatRejected);
        }
        if ($status === 408 || $status === 425 || ($status >= 500 && $status < 600)) {
            return $make(Outcome::ServerError, new HttpException(sprintf('HTTP %d: server error.', $status), $status, $excerpt()));
        }

        return $make(Outcome::HttpError, new HttpException(sprintf('HTTP %d: unexpected status.', $status), $status, $excerpt()));
    }

    /**
     * @param array<mixed>|null $json
     * @param list<string>      $secrets
     */
    private function readSuccess(int $status, string $body, ?array $json, string $lower, Provider $provider, ?string $requestId, array $secrets): Classified
    {
        $invalid = static fn (string $why, Usage $usage = new Usage(), ?string $id = null, ?string $model = null): Classified => new Classified(
            Outcome::InvalidResponse,
            new InvalidResponseException($why),
            usage: $usage,
            responseId: $id,
            reportedModel: $model,
            requestId: $requestId,
        );

        if ($body === '') {
            return $invalid('Empty response body.');
        }
        if (!Text::isUtf8($body)) {
            return $invalid('Response body is not valid UTF-8.');
        }
        if ($json === null || array_is_list($json)) {
            return $invalid('Response body is not a JSON object (a proxy page or a cut-off body).');
        }

        $usage = $this->usage($json);
        $id = is_string($json['id'] ?? null) ? Text::cut($json['id'], 200) : null;
        $model = is_string($json['model'] ?? null) ? Text::cut($json['model'], 200) : null;

        if (is_array($json['error'] ?? null)) {
            return $this->errorInSuccess($json['error'], $lower, $json, $provider, $usage, $id, $model, $requestId, $secrets);
        }
        $choices = $json['choices'] ?? null;
        if (!is_array($choices) || $choices === [] || !is_array($choices[0] ?? null)) {
            return $invalid('Response has no choices.', $usage, $id, $model);
        }
        $choice = $choices[0];
        if (is_array($choice['error'] ?? null)) {
            return $this->errorInSuccess($choice['error'], $lower, $json, $provider, $usage, $id, $model, $requestId, $secrets);
        }

        $finish = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $common = ['usage' => $usage, 'responseId' => $id, 'reportedModel' => $model, 'requestId' => $requestId];

        if ($finish === 'length') {
            return new Classified(Outcome::Truncated, new TruncatedOutputException('Finish reason length: the output was cut off at the token cap.'), ...$common);
        }
        if ($finish === 'error') {
            // OpenRouter's normalised finish reason for an upstream failure
            // during generation: whatever content came back is not trusted.
            return new Classified(Outcome::ProviderError, new InvalidResponseException('Finish reason error: the upstream host failed during generation.'), ...$common);
        }
        if ($finish === 'content_filter') {
            return new Classified(Outcome::ContentFilter, new ContentFilterException('Finish reason content_filter.'), ...$common);
        }

        $content = $message['content'] ?? null;
        if (is_array($content)) {
            $joined = '';
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $joined .= $part['text'];
                }
            }
            $content = $joined;
        }
        $content = is_string($content) ? trim($content) : null;

        if ($content === null || $content === '') {
            $refusal = $message['refusal'] ?? null;
            if (is_string($refusal) && trim($refusal) !== '') {
                return new Classified(Outcome::Refusal, new RefusalException(Text::cut(trim($refusal), self::REFUSAL_EXCERPT)), ...$common);
            }
            if (!empty($message['tool_calls'])) {
                return new Classified(Outcome::InvalidResponse, new InvalidResponseException('Response has tool calls and no content; no tools were sent.'), ...$common);
            }

            return new Classified(Outcome::InvalidResponse, new InvalidResponseException('Response content is empty.'), ...$common);
        }

        return new Classified(Outcome::Success, null, $content, ...$common);
    }

    /**
     * @param array<mixed> $error
     * @param array<mixed> $json
     * @param list<string> $secrets
     */
    private function errorInSuccess(array $error, string $lower, array $json, Provider $provider, Usage $usage, ?string $id, ?string $model, ?string $requestId, array $secrets): Classified
    {
        $code = $error['code'] ?? null;
        $code = is_scalar($code) ? (string) $code : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'provider error';
        $excerpt = Redactor::excerpt($message, self::ERROR_EXCERPT, $secrets);
        if ($code === '402' || $this->isCredit($lower, ['error' => $error], $provider)) {
            return new Classified(
                Outcome::CreditExhausted,
                new CreditExhaustedAttemptException('Provider error inside a 200: credit exhausted.', 402, $excerpt),
                usage: $usage,
                responseId: $id,
                reportedModel: $model,
                requestId: $requestId,
                errorExcerpt: $excerpt,
            );
        }

        return new Classified(
            Outcome::ProviderError,
            new InvalidResponseException('Provider error inside a 200 response' . ($code !== null ? ' (code ' . Text::cut($code, 40) . ')' : '') . '.'),
            usage: $usage,
            responseId: $id,
            reportedModel: $model,
            requestId: $requestId,
            errorExcerpt: $excerpt,
        );
    }

    /**
     * @param array<mixed>|null $json
     */
    private function isCredit(string $lower, ?array $json, Provider $provider): bool
    {
        $code = is_array($json['error'] ?? null) ? ($json['error']['code'] ?? null) : null;
        if (is_scalar($code) && (string) $code === '402') {
            return true;
        }
        foreach ($provider->creditPatterns() as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed>|null $json
     */
    private function usage(?array $json): Usage
    {
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
        if ($usage === null) {
            return new Usage();
        }
        $int = static fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : (is_float($v) && $v >= 0 && floor($v) === $v ? (int) $v : null);
        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $completionDetails = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];
        $cost = $usage['cost'] ?? null;
        $cost = (is_int($cost) || is_float($cost)) && $cost >= 0 ? (float) $cost : null;

        return new Usage(
            $int($usage['prompt_tokens'] ?? null),
            $int($usage['completion_tokens'] ?? null),
            $int($completionDetails['reasoning_tokens'] ?? null),
            $int($promptDetails['cached_tokens'] ?? null),
            $cost,
            $cost === null ? \IndianOpinion\LlmJson\CostSource::Unknown : \IndianOpinion\LlmJson\CostSource::Reported,
        );
    }

    public function retryAfter(?string $header, float $now): ?float
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $header = trim($header);
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $header, new \DateTimeZone('UTC'));
        if ($date === false) {
            $timestamp = strtotime($header);
            if ($timestamp === false) {
                return null;
            }

            return max(0.0, $timestamp - $now);
        }

        return max(0.0, $date->getTimestamp() - $now);
    }
}
