<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * A chat completions endpoint and the key for it. Immutable.
 *
 * The API key is never logged, never put in an exception and never stored.
 * __debugInfo() hides it from var_dump() and print_r().
 */
final class Provider
{
    public const OPENROUTER_URL = 'https://openrouter.ai/api/v1';
    public const OPENAI_URL = 'https://api.openai.com/v1';

    /** @var list<string> */
    public const DEFAULT_CREDIT_PATTERNS = [
        'insufficient_quota',
        'insufficient quota',
        'credit_balance_exhausted',
        'insufficient credits',
        'insufficient_credits',
        'requires more credits',
        'exceeded your current quota',
        'billing_hard_limit_reached',
        'billing_not_active',
        'billing details',
        'payment required',
    ];

    /** Header names a caller may not override through extra headers. */
    private const RESERVED_HEADERS = ['authorization', 'content-type', 'content-length', 'host'];

    private string $baseUrl;
    /** @var array<string, string> */
    private array $headers = [];
    private string $tokenParameter;
    /** @var list<string> */
    private array $creditPatterns = self::DEFAULT_CREDIT_PATTERNS;
    private bool $supportsStrict;
    private bool $supportsJsonObject;

    private function __construct(
        private readonly string $name,
        private readonly ProviderKind $kind,
        string $baseUrl,
        private readonly string $apiKey,
        private readonly bool $keyless,
        private readonly bool $allowPlainHttp,
    ) {
        if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) !== 1) {
            throw new ConfigurationException('Provider name must be 1 to 64 letters, digits, underscores, hyphens or dots.');
        }
        if ($apiKey === '' && !($keyless && $kind === ProviderKind::Generic)) {
            throw new ConfigurationException(sprintf('Provider "%s" has an empty API key. Only a generic provider marked keyless may have none.', $name));
        }
        if (preg_match('/[\r\n]/', $apiKey) === 1) {
            throw new ConfigurationException(sprintf('Provider "%s" API key contains a line break.', $name));
        }
        $this->baseUrl = self::checkUrl($name, $baseUrl, $allowPlainHttp);
        $this->tokenParameter = $kind === ProviderKind::OpenAi ? 'max_completion_tokens' : 'max_tokens';
        $this->supportsStrict = $kind !== ProviderKind::Generic;
        $this->supportsJsonObject = $kind !== ProviderKind::Generic;
    }

    /**
     * OpenRouter. The referer and title are OpenRouter's optional
     * attribution headers and are sent only when given.
     */
    public static function openRouter(string $apiKey, ?string $referer = null, ?string $title = null, string $name = 'openrouter'): self
    {
        $provider = new self($name, ProviderKind::OpenRouter, self::OPENROUTER_URL, $apiKey, false, false);
        $headers = [];
        if ($referer !== null && $referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }
        if ($title !== null && $title !== '') {
            $headers['X-Title'] = $title;
        }

        return $headers === [] ? $provider : $provider->withHeaders($headers);
    }

    public static function openAi(string $apiKey, string $name = 'openai'): self
    {
        return new self($name, ProviderKind::OpenAi, self::OPENAI_URL, $apiKey, false, false);
    }

    /**
     * Any server that speaks chat completions. Structured output support is
     * off by default (prompt mode); turn it on with withStructuredOutputs().
     */
    public static function generic(
        string $name,
        string $baseUrl,
        string $apiKey = '',
        bool $keyless = false,
        bool $allowPlainHttp = false,
    ): self {
        return new self($name, ProviderKind::Generic, $baseUrl, $apiKey, $keyless, $allowPlainHttp);
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $clone = clone $this;
        $clone->baseUrl = self::checkUrl($this->name, $baseUrl, $this->allowPlainHttp);

        return $clone;
    }

    /**
     * Extra headers sent on every request. Authorization, Content-Type,
     * Content-Length and Host cannot be set here.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $header => $value) {
            if (preg_match('/^[A-Za-z0-9\-]+$/', $header) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new ConfigurationException(sprintf('Header "%s" on provider "%s" is not a valid header.', $header, $this->name));
            }
            if (in_array(strtolower($header), self::RESERVED_HEADERS, true)) {
                throw new ConfigurationException(sprintf('Header "%s" cannot be set as an extra header.', $header));
            }
        }
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /** Name of the output token cap parameter (max_tokens or max_completion_tokens). */
    public function withTokenParameter(string $parameter): self
    {
        if (preg_match('/^[a-z_]{1,64}$/', $parameter) !== 1) {
            throw new ConfigurationException('Token cap parameter must be lower-case letters and underscores.');
        }
        $clone = clone $this;
        $clone->tokenParameter = $parameter;

        return $clone;
    }

    /**
     * Body substrings (case-insensitive) that mean the credit ran out.
     *
     * @param list<string> $patterns
     */
    public function withCreditPatterns(array $patterns, bool $replace = false): self
    {
        foreach ($patterns as $pattern) {
            if (trim($pattern) === '') {
                throw new ConfigurationException('Credit patterns must not be empty.');
            }
        }
        $clone = clone $this;
        $clone->creditPatterns = array_values(array_unique(array_map('strtolower', $replace ? $patterns : array_merge($this->creditPatterns, $patterns))));

        return $clone;
    }

    /** What the endpoint supports, for output mode auto. */
    public function withStructuredOutputs(bool $strict, bool $jsonObject): self
    {
        $clone = clone $this;
        $clone->supportsStrict = $strict;
        $clone->supportsJsonObject = $jsonObject;

        return $clone;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): ProviderKind
    {
        return $this->kind;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function endpoint(): string
    {
        return $this->baseUrl . '/chat/completions';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function tokenParameter(): string
    {
        return $this->tokenParameter;
    }

    /** @return list<string> */
    public function creditPatterns(): array
    {
        return $this->creditPatterns;
    }

    public function supportsStrict(): bool
    {
        return $this->supportsStrict;
    }

    public function supportsJsonObject(): bool
    {
        return $this->supportsJsonObject;
    }

    public function isKeyless(): bool
    {
        return $this->apiKey === '';
    }

    /**
     * The Authorization header value. Internal: used by the client to build
     * the request; never logged.
     *
     * @internal
     */
    public function authorization(): ?string
    {
        return $this->apiKey === '' ? null : 'Bearer ' . $this->apiKey;
    }

    /** A short hash of the key, safe to store or show. */
    public function keyFingerprint(): string
    {
        return substr(hash('sha256', 'llmjson-key:' . $this->apiKey), 0, 12);
    }

    /** Identity of the account: base URL plus key fingerprint. */
    public function id(): string
    {
        return substr(hash('sha256', $this->baseUrl . "\0" . $this->keyFingerprint()), 0, 16);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->apiKey === '' ? '' : '[redacted ' . $this->keyFingerprint() . ']',
        ];
    }

    private static function checkUrl(string $name, string $url, bool $allowPlainHttp): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(sprintf('Provider "%s" base URL is not a valid URL.', $name));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(sprintf('Provider "%s" base URL must not carry credentials, a query or a fragment.', $name));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme !== 'http') {
            throw new ConfigurationException(sprintf('Provider "%s" base URL must use https.', $name));
        }
        if ($allowPlainHttp || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $url;
        }
        throw new ConfigurationException(sprintf('Provider "%s" base URL uses plain http. The API key travels in a header, so only https is allowed, except for localhost or a provider created with allowPlainHttp.', $name));
    }
}
