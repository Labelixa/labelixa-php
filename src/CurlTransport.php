<?php

declare(strict_types=1);

namespace Labelixa;

/**
 * Default transport, built on ext-curl — present in every PHP install
 * that talks to an API. Requiring an HTTP client library for eight
 * endpoints would be rude to someone else's composer.lock.
 */
final class CurlTransport implements Transport
{
    public function __construct(private int $timeout = 60)
    {
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
    ): array {
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            // Never follow a redirect: the X-API-Key header would go to
            // whatever host the Location names. The API does not
            // redirect, so a 3xx surfaces as an error with its status.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $n, string $v): string => $n . ': ' . $v,
                array_keys($headers),
                array_values($headers),
            ),
            CURLOPT_HEADERFUNCTION => static function (
                $ch,
                string $line,
            ) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))]
                        = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new LabelixaException(0, 'Request failed: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => (string) $raw,
            'headers' => $responseHeaders,
        ];
    }
}
