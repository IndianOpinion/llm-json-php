<?php

/**
 * A tiny stand-in for the WordPress functions the library touches, so the
 * WordPress transport and store can be tested (and analysed) without
 * WordPress. State lives in $GLOBALS['llmjson_wp'].
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

if (!function_exists('wp_remote_post')) {
    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|WP_Error
     */
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        $GLOBALS['llmjson_wp']['calls'][] = ['url' => $url, 'args' => $args];
        $next = array_shift($GLOBALS['llmjson_wp']['responses']);

        return $next ?? new WP_Error('http_request_failed', 'no stub response');
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }

    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    /**
     * Real WordPress returns a case-insensitive dictionary object; the stub
     * returns an array.
     *
     * @param array<string, mixed>|WP_Error $response
     */
    function wp_remote_retrieve_headers(array|WP_Error $response): mixed
    {
        return is_array($response) ? (array) ($response['headers'] ?? []) : [];
    }

    function get_transient(string $key): mixed
    {
        $entry = $GLOBALS['llmjson_wp']['transients'][$key] ?? null;

        return $entry === null ? false : $entry['value'];
    }

    function set_transient(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['llmjson_wp']['transients'][$key] = ['value' => $value, 'ttl' => $ttl];

        return true;
    }

    function delete_transient(string $key): bool
    {
        unset($GLOBALS['llmjson_wp']['transients'][$key]);

        return true;
    }

    function get_site_transient(string $key): mixed
    {
        $entry = $GLOBALS['llmjson_wp']['site_transients'][$key] ?? null;

        return $entry === null ? false : $entry['value'];
    }

    function set_site_transient(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['llmjson_wp']['site_transients'][$key] = ['value' => $value, 'ttl' => $ttl];

        return true;
    }

    function delete_site_transient(string $key): bool
    {
        unset($GLOBALS['llmjson_wp']['site_transients'][$key]);

        return true;
    }

    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['llmjson_wp']['multisite'] ?? false);
    }

    function wp_using_ext_object_cache(): bool
    {
        return (bool) ($GLOBALS['llmjson_wp']['ext_cache'] ?? false);
    }

    function wp_cache_add(string $key, mixed $value, string $group = '', int $ttl = 0): bool
    {
        if (isset($GLOBALS['llmjson_wp']['cache'][$group][$key])) {
            return false;
        }
        $GLOBALS['llmjson_wp']['cache'][$group][$key] = $value;

        return true;
    }

    function llmjson_wp_reset(): void
    {
        $GLOBALS['llmjson_wp'] = ['calls' => [], 'responses' => [], 'transients' => [], 'site_transients' => [], 'cache' => [], 'multisite' => false, 'ext_cache' => false];
    }

    llmjson_wp_reset();
}
