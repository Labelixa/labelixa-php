<?php

declare(strict_types=1);

namespace Labelixa;

/**
 * WIRE VOCABULARY: the API's own field names that this client reads by
 * name, in one file.
 *
 * Some fields of the Labelixa API are named in Turkish. They are PROTOCOL:
 * renaming them here would not translate anything, it would simply stop
 * reading the server's answer. So they are not translated, and they are
 * not hidden either.
 *
 * They live in this single file so the publish guard can exempt exactly one
 * file from the "no Turkish words" rule instead of losing that rule across
 * the whole package. The guard still checks this file for ticket ids,
 * non-English characters and audit markers.
 *
 * Reference: https://labelixa.com/docs/api
 */
final class Contract
{
    /** An error answer (any non-2xx) is {"hata": "<the server's sentence>"}. */
    public const ERROR_FIELD = 'hata';

    /**
     * An error answer MAY also carry a machine-readable code, {"kod": "..."}
     * (for example a missing scope or a rate limit). Stable across
     * languages, unlike the sentence.
     */
    public const CODE_FIELD = 'kod';

    /** Print-job and trial errors name their error class in {"hata_tipi": "..."}. */
    public const ERROR_TYPE_FIELD = 'hata_tipi';

    /**
     * The sentence of an API error answer, or the body as it came when it
     * has any other shape. Capped at 500 bytes either way.
     */
    public static function serverText(string $raw): string
    {
        $text = $raw;
        $body = json_decode($raw, true);
        if (is_array($body) && is_string($body[self::ERROR_FIELD] ?? null)) {
            $text = $body[self::ERROR_FIELD];
        }
        return substr($text, 0, 500);
    }
}
