<?php

declare(strict_types=1);

namespace Labelixa;

use RuntimeException;

/**
 * Carries the server's own message verbatim. Wrapping it in a friendlier
 * sentence would hide the only text that says what is actually wrong.
 */
class LabelixaException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $serverMessage,
    ) {
        parent::__construct(sprintf('HTTP %d: %s', $status, $serverMessage));
    }
}
