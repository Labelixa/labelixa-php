<?php

declare(strict_types=1);

namespace Labelixa;

/**
 * Thrown for 402 and 429.
 *
 * A separate class on purpose: a caller that cannot tell a quota
 * response from a generic failure either never retries or retries
 * immediately, and both are wrong. $retryAfter is the server's
 * Retry-After in seconds; $action is its hint ("upgrade", "addon") when
 * it sends one.
 */
final class QuotaExceededException extends LabelixaException
{
    public function __construct(
        int $status,
        string $serverMessage,
        public readonly int $retryAfter,
        public readonly ?string $action,
    ) {
        parent::__construct($status, $serverMessage);
    }
}
