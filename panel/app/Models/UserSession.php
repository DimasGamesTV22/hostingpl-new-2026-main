<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class UserSession extends Model
{
    protected $fillable = [
        'user_id', 'ip', 'user_agent', 'device', 'location',
        'remember', 'last_activity_at', 'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'remember' => 'boolean',
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            $s->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('last_activity_at', '>=', now()->subMinutes((int) config('hosting.auth.session.idle_timeout_minutes', 60)));
    }

    public function isCurrent(): bool
    {
        return $this->ip === request()->ip() && $this->user_agent === mb_substr((string) request()->userAgent(), 0, 512);
    }

    public function deviceLabel(): string
    {
        return $this->device ?: __('auth.sessions.unknown_device');
    }

    public function lastSeenHuman(): string
    {
        if (! $this->last_activity_at) {
            return '—';
        }

        $diff = Carbon::parse($this->last_activity_at)->diffForHumans();

        return $diff;
    }

    /** Определяет ОС/браузер из user-agent. */
    public static function detectDevice(?string $ua): string
    {
        if (blank($ua)) {
            return 'Неизвестно';
        }

        $os = 'Неизвестная ОС';
        $osPatterns = [
            'Windows NT 10' => 'Windows 10/11',
            'Windows NT' => 'Windows',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Mac OS X' => 'macOS',
            'Linux' => 'Linux',
            'FreeBSD' => 'FreeBSD',
        ];

        foreach ($osPatterns as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $os = $label;
                break;
            }
        }

        $browser = 'Браузер';
        $browserPatterns = [
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'Safari/' => 'Safari',
            'YandexBrowser' => 'Яндекс.Браузер',
            'curl/' => 'curl',
        ];

        foreach ($browserPatterns as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $browser = $label;
                break;
            }
        }

        return $os.' · '.$browser;
    }
}
