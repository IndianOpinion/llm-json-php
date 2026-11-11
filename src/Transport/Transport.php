<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Transport;

use IndianOpinion\LlmJson\Exception\TransportException;

/**
 * Sends one HTTP request. Must not follow redirects. Any status code is a
 * response, not an error; only a failure to get a response throws.
 */
interface Transport
{
    /**
     * @throws TransportException when no response arrived
     */
    public function send(HttpRequest $request): HttpResponse;
}
