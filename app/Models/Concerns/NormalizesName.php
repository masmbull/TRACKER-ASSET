<?php

namespace App\Models\Concerns;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the `name` of a lookup table free of invisible characters.
 *
 * PRD §17-§20 make the name the identity of a company, location, category and
 * status, so two rows whose names differ only by a byte order mark are one
 * entity stored twice. Applied as a mutator rather than at each call site
 * because the writers are spread across the importer, the seeders, the master
 * data form and the tests, and a caller that forgets to tidy its input is
 * enough to split the entity again.
 */
trait NormalizesName
{
    /**
     * The column is NOT NULL, so a name that normalises away is stored as an
     * empty string and left for validation or the database to reject. Silently
     * keeping the dirty value would defeat the point.
     */
    public function setNameAttribute(mixed $value): void
    {
        $this->attributes['name'] = NameNormalizer::normalize($value) ?? '';
    }

    /**
     * Look a row up by name the way a person would type it.
     *
     * The stored value is already normalised by the mutator, so the exact match
     * is the fast path. The case-insensitive fallback exists because MySQL's
     * default collation folds case: a unique index on `name` there rejects
     * "jakarta" against "Jakarta" while SQLite happily stores both. Matching
     * the same way the index compares keeps a lookup from turning into an
     * integrity error on the next insert.
     */
    public function scopeWhereNameIs(Builder $query, ?string $name): Builder
    {
        $normalized = NameNormalizer::normalize($name);

        if ($normalized === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($normalized): void {
            $query->where('name', $normalized)
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($normalized)]);
        });
    }
}
