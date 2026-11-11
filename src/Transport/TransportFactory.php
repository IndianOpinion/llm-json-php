<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * Picks the default transport: curl, else PHP streams.
 */
final class TransportFactory
{
    public static function default(): Transport
    {
        return self::choose(CurlTransport::available(), StreamTransport::available());
    }

    /** @internal Testable choice. */
    public static function choose(bool $curl, bool $streams): Transport
    {
        if ($curl) {
            return new CurlTransport();
        }
        if ($streams) {
            return new StreamTransport();
        }
        throw new ConfigurationException('No HTTP transport is available: install ext-curl (recommended), or enable allow_url_fopen with ext-openssl, or pass WordPressTransport inside WordPress.');
    }
}
