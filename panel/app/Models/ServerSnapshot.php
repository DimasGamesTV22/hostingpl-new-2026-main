<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ServerSnapshot extends Model
{
    use HasFactory;

    public const TYPE_AUTO = 'auto';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_PRE_UPGRADE = 'pre_upgrade';
    public const TYPE_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'server_id', 'user_id', 'name', 'type', 'schedule', 'status',
        'size_bytes', 'path', 's3_key', 'checksum', 'file_count',
        'is_locked', 'is_uploaded', 'error', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'is_uploaded' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $snapshot) {
            $snapshot->uuid ??= (string) Str::uuid();
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DONE);
    }

    public function scopeScheduled(Builder $query, ?string $schedule = null): Builder
    {
        return $query->where('type', self::TYPE_AUTO)
            ->when($schedule, fn (Builder $q, string $s) => $q->where('schedule', $s));
    }

    public function sizeHuman(): string
    {
        return self::formatBytes((int) $this->size_bytes);
    }

    public function durationHuman(): string
    {
        $ms = (int) $this->duration_ms;
        if ($ms < 1000) {
            return $ms.' мс';
        }

        return round($ms / 1000, 1).' с';
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 Б';
        }

        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $precision).' '.$units[$power];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && Carbon::parse($this->expires_at)->isPast();
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DONE => 'green',
            self::STATUS_RUNNING => 'yellow',
            self::STATUS_FAILED => 'red',
            default => 'gray',
        };
    }

    public function ageHuman(): string
    {
        return $this->created_at?->diffForHumans() ?? '—';
    }
}
