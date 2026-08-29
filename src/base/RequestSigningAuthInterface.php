<?php

namespace justinholtweb\erpy\base;

/**
 * For schemes whose header depends on the request being made.
 *
 * OAuth 1.0a signs the method, the URL and every query parameter, so a flat `headers()` cannot
 * express it. Transport checks for this interface and hands over the request when it finds it.
 */
interface RequestSigningAuthInterface extends AuthInterface
{
    public function headersForRequest(string $method, string $url, array $query = []): array;
}
