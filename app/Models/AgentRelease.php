<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One shipped build of TrackerAgent.exe.
 *
 * The release history rather than a single setting, because the question an
 * operator actually asks is "what is on the laptops that did not take the last
 * update" - which needs the previous version, not just the current one.
 *
 * Versions are compared as numbers, never as strings: "1.10.0" sorts below
 * "1.9.0" alphabetically, and a fleet silently stuck on an older build is
 * precisely the failure this table exists to make visible.
 */
class AgentRelease extends Model
{
    protected $fillable = ['version', 'notes', 'released_at', 'is_current'];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    /**
     * Normalise whatever the build wrote into something version_compare accepts.
     *
     * Assembly versions are not always plain numbers - "1.2.3+9f1c2b" and
     * "1.2.3-beta" both occur - and version_compare treats a non-numeric
     * suffix unpredictably. The numeric prefix is what orders releases; the rest
     * is metadata that never decides an update.
     */
    public static function normalize(string $version): string
    {
        preg_match('/(\d+(?:\.\d+)*)/', $version, $m);

        return $m[1] ?? '0';
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->latest('released_at')->first()
            ?? static::query()->latest('released_at')->first();
    }

    /**
     * The release before the current one.
     *
     * "Before" is by release time, not by comparing version strings: a rollback
     * (shipping 1.9.0 after 1.10.0) makes the numerically smaller build the
     * current one, and whatever was live before it is still the most recently
     * released row above it.
     */
    public static function previous(): ?self
    {
        $current = static::current();

        if ($current === null) {
            return null;
        }

        return static::query()
            ->whereKeyNot($current->getKey())
            ->latest('released_at')
            ->latest('id')
            ->first();
    }

    /**
     * Register a new build as the current release.
     *
     * Done here rather than by editing rows so exactly one row is ever current:
     * two current releases would make "what the download button serves"
     * ambiguous, which is the whole reason this table exists.
     */
    public static function ship(string $version, ?string $notes = null, ?string $releasedAt = null): self
    {
        static::query()->update(['is_current' => false]);

        return static::create([
            'version' => $version,
            'notes' => $notes,
            'released_at' => $releasedAt ?: now(),
            'is_current' => true,
        ]);
    }

    /**
     * Is a device running something older than what we are serving?
     *
     * An unknown version returns null rather than true: a device that never
     * reported one has not been shown to be behind, and telling an operator it
     * is would send them chasing a problem that may not exist.
     */
    public static function isBehind(?string $runningVersion): ?bool
    {
        $current = static::current();

        if ($current === null || blank($runningVersion)) {
            return null;
        }

        return version_compare(
            static::normalize($runningVersion),
            static::normalize($current->version),
            '<'
        );
    }

    /** Is this device behind the current release? null when it never said. */
    public function isDeviceBehind(?string $runningVersion): ?bool
    {
        return static::isBehind($runningVersion);
    }
}
