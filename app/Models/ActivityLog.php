<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id', 'action', 'entity_type', 'entity_id', 'entity_identifier',
        'field_changed', 'old_value', 'new_value', 'source', 'metadata',
        'ip_address', 'user_agent', 'request_method', 'request_url', 'referer',
        'server_host', 'route_name', 'request_id', 'session_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Every one of the ~35 call sites creates a log with actor/action/entity
        // only. Capturing here keeps them all consistent and stops a new call
        // site from silently forgetting the request context.
        static::creating(function (self $log): void {
            // Scheduled jobs and queue workers have no user request to describe.
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }

            if (! app()->bound('request')) {
                return;
            }

            $request = request();

            // An explicit value wins: the agent API, for example, already knows
            // the device IP and must not have it replaced by the web request's.
            $log->ip_address = filled($log->ip_address) ? $log->ip_address : self::clean($request->ip(), 64);
            $log->user_agent = filled($log->user_agent) ? $log->user_agent : self::clean($request->userAgent(), 1000);
            $log->request_method = filled($log->request_method) ? $log->request_method : self::clean($request->method(), 10);
            $log->request_url = filled($log->request_url) ? $log->request_url : self::clean($request->fullUrl(), 2000);
            $log->referer = filled($log->referer) ? $log->referer : self::clean($request->header('referer'), 2000);
            $log->server_host = filled($log->server_host) ? $log->server_host : self::clean($request->getHost(), 255);
            $log->route_name = filled($log->route_name) ? $log->route_name : self::clean($request->route()?->getName(), 128);
            $log->request_id = filled($log->request_id) ? $log->request_id : self::clean($request->header('X-Request-ID'), 64);

            // Never store a raw session identifier: a hash still correlates the
            // events of one login without being usable to take it over.
            if ($request->hasSession() && $request->session()->isStarted()) {
                $log->session_hash = hash('sha256', $request->session()->getId());
            }
        });
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        // Log injection: CR/LF and other control characters must never make it
        // into a structured audit row, even if the client sent them.
        $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        return $value !== '' ? Str::limit($value, $max, '') : null;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Browser name derived from the stored user agent, without a dependency. */
    public function browserName(): string
    {
        $ua = (string) $this->user_agent;

        foreach ([
            'Edg/' => 'Microsoft Edge',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'Safari/' => 'Safari',
            'MSIE ' => 'Internet Explorer',
            'curl/' => 'cURL',
            'PostmanRuntime/' => 'Postman',
            'python-requests/' => 'Python requests',
        ] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return $ua !== '' ? 'Other' : '—';
    }

    /** Operating system derived from the stored user agent. */
    public function platformName(): string
    {
        $ua = (string) $this->user_agent;

        foreach ([
            'Windows NT 10.0' => 'Windows 10 / 11',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.1' => 'Windows 7',
            'Android' => 'Android',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Mac OS X' => 'macOS',
            'Linux' => 'Linux',
        ] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return $ua !== '' ? 'Other' : '—';
    }

    public function deviceType(): string
    {
        $ua = (string) $this->user_agent;

        if ($ua === '') {
            return '—';
        }

        if (preg_match('/iPhone|Android.*Mobile|Windows Phone/i', $ua)) {
            return 'Phone';
        }

        if (preg_match('/iPad|Tablet/i', $ua)) {
            return 'Tablet';
        }

        if (preg_match('/bot|crawler|spider|curl|python-requests|PostmanRuntime/i', $ua)) {
            return 'Automation';
        }

        return 'Desktop';
    }

    /** Mismatch data lives in metadata, not in a dedicated column. */
    public function mismatches(): array
    {
        $mismatches = $this->metadata['mismatches'] ?? null;

        return is_array($mismatches) ? $mismatches : [];
    }

    /**
     * Decode the JSON-ish value columns into a readable before/after pair.
     *
     * @return array{fields: array<int, string>, old: array<string, mixed>, new: array<string, mixed>, raw_old: bool, raw_new: bool}
     */
    public function changes(): array
    {
        $fields = $this->decode($this->field_changed);
        $old = $this->decode($this->old_value);
        $new = $this->decode($this->new_value);

        return [
            'fields' => is_array($fields) ? array_values($fields) : [],
            'old' => is_array($old) ? $old : [],
            'new' => is_array($new) ? $new : [],
            'raw_old' => ! is_array($old) && filled($this->old_value),
            'raw_new' => ! is_array($new) && filled($this->new_value),
        ];
    }

    private function decode(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
