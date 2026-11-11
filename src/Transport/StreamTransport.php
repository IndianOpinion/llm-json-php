<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Internal\ErrorTrap;

/**
 * Fallback for hosts without ext-curl, using PHP's HTTP stream wrapper.
 * Needs allow_url_fopen, and ext-openssl for https. TLS verification is
 * always on and redirects are never followed.
 *
 * Limitation: PHP streams have no separate connect timeout, so the
 * request timeout covers connecting and waiting for the headers, and the
 * total time is checked between reads of the body.
 */
final class StreamTransport implements Transport
{
    public function __construct()
    {
        if (!self::available()) {
            throw new ConfigurationException('StreamTransport needs allow_url_fopen and ext-openssl.');
        }
    }

    public static function available(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && extension_loaded('openssl');
    }

    /**
     * The stream context options used for a request.
     *
     * @return array<string, array<string, mixed>>
     */
    public function contextOptions(HttpRequest $request): array
    {
        $headers = ['Connection: close'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return [
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $headers),
                'content' => $request->body,
                'timeout' => $request->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 1,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $start = microtime(true);
        $context = stream_context_create($this->contextOptions($request));
        [$handle, $warning] = ErrorTrap::run(static fn () => fopen($request->url, 'rb', false, $context));
        if (!is_resource($handle)) {
            $message = $warning ?? 'unknown error';
            throw new TransportException(
                'Stream error: ' . $message,
                tlsVerification: stripos($message, 'certificate verify failed') !== false,
                timeout: stripos($message, 'timed out') !== false
                    || microtime(true) - $start >= 0.9 * $request->timeout,
            );
        }
        $meta = stream_get_meta_data($handle);
        /** @var list<string> $lines */
        $lines = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $body = '';
        try {
            while (!feof($handle)) {
                $remaining = $request->timeout - (microtime(true) - $start);
                if ($remaining <= 0) {
                    throw new TransportException('Request timed out.', timeout: true);
                }
                stream_set_timeout($handle, max(1, (int) ceil($remaining)));
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    throw new TransportException('Stream read failed.');
                }
                if (stream_get_meta_data($handle)['timed_out']) {
                    throw new TransportException('Request timed out.', timeout: true);
                }
                if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) {
                    throw new TransportException(sprintf('Response body passed the %d byte cap and was aborted.', $request->maxResponseBytes), bodyTooLarge: true);
                }
                $body .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        return new HttpResponse($status, $headers, $body);
    }
}
