<?php

declare(strict_types=1);

namespace Labelixa;

/**
 * How the client reaches the network.
 *
 * It exists for two reasons. First, it makes the client testable without
 * a socket. Second, an application that already has an HTTP stack — a
 * framework client with its own retries, proxy settings, logging or
 * connection pool — can hand it over instead of having a second one
 * appear inside it.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     *                Response header names must be lower-cased: header
     *                names are case-insensitive and callers should not
     *                have to guess which spelling a proxy used.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
    ): array;
}
