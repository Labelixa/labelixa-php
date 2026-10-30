<?php

declare(strict_types=1);

namespace Labelixa;

use RuntimeException;

/**
 * Carries the server's own message verbatim. Wrapping it in a friendlier
 * sentence would hide the only text that says what is actually wrong.
 *
 * getErrorCode() and getErrorType() return the machine-readable fields of
 * the error answer, when the server sends them: the error code identifies
 * the error (stable, unlike the sentence) and the error type names the
 * error class of print-job and trial errors. Both are null when the body
 * is not JSON or the field is absent. (They are not called getCode():
 * that method is the built-in integer exception code and is final.)
 */
class LabelixaException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $serverMessage,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorType = null,
    ) {
        parent::__construct(sprintf('HTTP %d: %s', $status, $serverMessage));
    }

    /** The error's machine-readable code, or null when the server sent none. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** The error class of a print-job or trial error, or null when the server sent none. */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }
}
