<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\TransportException;

/**
 * Sends requests through wp_remote_post(), so WordPress proxy settings,
 * logging and host rules apply. Redirects are off (redirection 0).
 *
 * WordPress uses one timeout for connect and transfer; the request timeout
 * is passed as that timeout.
 */
final class WordPressTransport implements Transport
{
    public function __construct()
    {
        if (!function_exists('wp_remote_post')) {
            throw new ConfigurationException('WordPressTransport needs WordPress loaded (wp_remote_post is missing).');
        }
    }

    /**
     * The arguments passed to wp_remote_post().
     *
     * @return array<string, mixed>
     */
    public function arguments(HttpRequest $request): array
    {
        return [
            'method' => $request->method,
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => $request->maxResponseBytes + 1,
            'user-agent' => $request->headers['User-Agent'] ?? 'llm-json-php',
        ];
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $response = wp_remote_post($request->url, $this->arguments($request));
        if ($response instanceof \WP_Error) {
            $message = (string) $response->get_error_message();
            throw new TransportException(
                'WordPress HTTP error: ' . $message,
                tlsVerification: stripos($message, 'certificate') !== false || stripos($message, 'SSL') !== false && stripos($message, 'verif') !== false,
                timeout: stripos($message, 'timed out') !== false,
            );
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > $request->maxResponseBytes) {
            throw new TransportException(sprintf('Response body passed the %d byte cap and was aborted.', $request->maxResponseBytes), bodyTooLarge: true);
        }
        $raw = wp_remote_retrieve_headers($response);
        $headers = [];
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        if (is_iterable($raw)) {
            foreach ($raw as $name => $value) {
                $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }
        }

        return new HttpResponse($status, $headers, $body);
    }
}
