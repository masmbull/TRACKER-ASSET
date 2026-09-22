<?php

namespace App\Services;

use App\Models\Document;

/**
 * Configurable, duplicate-proof document numbering (PRD §33).
 *
 * Formats are defined in config/documents.php with {year} and {seq:N} tokens.
 * Sequences are per document type and per year and are derived from the highest
 * number already issued — never from a plain row count.
 */
class DocumentNumberService
{
    public function next(string $type): string
    {
        $format = (string) config("documents.numbering.{$type}", '');

        if ($format === '' || ! str_contains($format, '{seq:')) {
            throw new \InvalidArgumentException("Unknown document type for numbering: {$type}");
        }

        $pattern = str_replace('{year}', now()->format('Y'), $format);

        preg_match('/\{seq:(\d+)\}/', $pattern, $m);
        $width = (int) $m[1];

        [$prefix, $suffix] = explode($m[0], $pattern, 2);

        $last = Document::query()
            ->where('document_type', $type)
            ->where('document_number', 'like', $prefix.'%'.$suffix)
            ->orderByDesc('document_number')
            ->value('document_number');

        $sequence = 1;

        if ($last !== null) {
            $sequence = ((int) substr($last, strlen($prefix), $width)) + 1;
        }

        return $prefix.str_pad((string) $sequence, $width, '0', STR_PAD_LEFT).$suffix;
    }
}
