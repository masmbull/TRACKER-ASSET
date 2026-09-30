<?php

namespace App\Support;

/**
 * The one definition of what a master data name is.
 *
 * Names arrive from three places that all carry invisible junk: spreadsheets
 * exported from Excel (a UTF-8 BOM on the first cell of every sheet), copy and
 * paste out of Word or a browser (zero-width and non-breaking spaces), and the
 * master data form. `trim()` removes none of it - PHP's default character list
 * is ASCII whitespace only - and PCRE's `\s` stays ASCII even under the /u
 * modifier, because that modifier enables UTF-8 encoding rather than Unicode
 * property matching. A regex built for "tidy up whitespace" therefore passes a
 * byte order mark straight through into the database.
 *
 * The register already carries the result. Companies 3 and 7 held the same
 * name, one of them prefixed with U+FEFF, and five assets were attached to the
 * invisible twin. Every dropdown, every generated document and every signature
 * block showed them as two separate organisations.
 *
 * Normalising on write keeps that from recurring; the migration normalises what
 * is already stored.
 */
class NameNormalizer
{
    /**
     * Characters that render as nothing at all.
     *
     * Removed rather than replaced with a space: they are not separators, and
     * treating them as one would split a single name into two words.
     */
    private const INVISIBLE = [
        "\u{200B}", // zero width space
        "\u{200C}", // zero width non-joiner
        "\u{200D}", // zero width joiner
        "\u{2060}", // word joiner
        "\u{FEFF}", // BOM / zero width no-break space
        "\u{00AD}", // soft hyphen
    ];

    /**
     * Characters that are invisible but are standing in for a space.
     *
     * These become an ordinary space. A non-breaking space between "PT." and
     * "Mito" is a word boundary that a human can see and search for; dropping
     * it would silently glue the words together.
     */
    private const INVISIBLE_SPACES = [
        "\u{00A0}", // no-break space
        "\u{2007}", // figure space
        "\u{2009}", // thin space
        "\u{202F}", // narrow no-break space
    ];

    /**
     * Normalise a raw value, or null when nothing printable is left.
     *
     * A name that is entirely invisible normalises to null rather than to the
     * empty string, so callers can tell "no name" from "a name of spaces".
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $text = str_replace(self::INVISIBLE, '', (string) $value);
        $text = str_replace(self::INVISIBLE_SPACES, ' ', $text);

        // preg_replace returns null on malformed UTF-8; keep the text we have
        // rather than losing the value entirely.
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }
}
