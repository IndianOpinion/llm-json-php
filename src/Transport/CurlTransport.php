<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\TransportException;

/**
 * The default transport. TLS verification is always on, redirects are never
 * followed, and the body is aborted once it passes the size cap. Proxies
 * work through curl's standard environment variables.
 */
final class CurlTransport implements Transport
{
    /** curl error codes that mean the peer certificate could not be verified. */
    private const TLS_VERIFY_ERRORS = [51, 58, 60, 77, 83, 90, 91];
    private const TIMEOUT_ERROR = 28;

    public function __construct()
    {
        if (!self::available()) {
            throw new ConfigurationException('CurlTransport needs ext-curl. Install it, or use StreamTransport or WordPressTransport.');
        }
    }

    public static function available(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    /**
     * The curl options used for a request, without the callbacks. Public so
     * that tests and the curious can check them.
     *
     * @return array<int, mixed>
     */
    public function options(HttpRequest $request): array
    {
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_POST => $request->method === 'POST',
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($request->connectTimeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
        ];
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('curl_init failed.');
        }
        $body = '';
        $tooLarge = false;
        $headers = [];
        $max = $request->maxResponseBytes;
        $options = $this->options($request);
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$body, &$tooLarge, $max): int {
            if (strlen($body) + strlen($chunk) > $max) {
                $tooLarge = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$headers): int {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];
            } elseif (str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        };
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new TransportException(sprintf('Response body passed the %d byte cap and was aborted.', $max), bodyTooLarge: true);
        }
        if ($ok === false || $errno !== 0) {
            throw new TransportException(
                sprintf('curl error %d: %s', $errno, $error),
                tlsVerification: in_array($errno, self::TLS_VERIFY_ERRORS, true),
                timeout: $errno === self::TIMEOUT_ERROR,
            );
        }

        return new HttpResponse($status, $headers, $body);
    }
}
