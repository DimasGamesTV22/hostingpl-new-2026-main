<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Crypto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Node extends Model
{
    use HasFactory;

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DISABLED = 'disabled';

    public const RUNTIMES = ['docker', 'podman', 'lxc', 'native'];

    protected $fillable = [
        'name', 'slug', 'description', 'connection_mode', 'host', 'agent_port',
        'tls', 'tls_ca', 'tls_fingerprint',
        'country', 'city', 'region', 'continent', 'latitude', 'longitude',
        'timezone', 'flagship', 'banner', 'features',
        'runtime', 'runtime_options', 'runtimes_available',
        'max_servers', 'max_memory_mb', 'max_disk_mb', 'max_cpu_percent',
        'allocatable_percent', 'reserved_memory_mb',
        'status', 'status_message', 'weight', 'region_priority',
        'prefer_over_region', 'is_default', 'is_active', 'allow_ssh_fallback',
    ];

    protected $hidden = ['token', 'tls_ca'];

    protected function casts(): array
    {
        return [
            'runtime_options' => 'array',
            'runtimes_available' => 'array',
            'features' => 'array',
            'tls' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'prefer_over_region' => 'boolean',
            'allow_ssh_fallback' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'token_rotated_at' => 'datetime',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $node) {
            if (blank($node->slug)) {
                $node->slug = Str::slug($node->name);
            }
            $node->uuid ??= (string) Str::uuid();
        });
    }

    // ── Отношения ───────────────────────────────────────────────────────

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ResourceAllocation::class);
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(NodeHealthLog::class);
    }

    // ── Токен ───────────────────────────────────────────────────────────

    public function setTokenAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['token'] = null;
            $this->attributes['token_hash'] = null;

            return;
        }

        $this->attributes['token'] = Crypto::encrypt($value);
        $this->attributes['token_hash'] = hash('sha256', $value);
        $this->attributes['token_rotated_at'] = now();
    }

    public function plainToken(): ?string
    {
        return $this->token ? Crypto::decrypt($this->token) : null;
    }

    public function verifyToken(string $candidate): bool
    {
        return $this->token_hash && hash_equals($this->token_hash, hash('sha256', $candidate));
    }

    public function rotateToken(): string
    {
        $token = Str::random(48);
        $this->token = $token;
        $this->save();

        return $token;
    }

    // ── Состояние ───────────────────────────────────────────────────────

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    public function isUsable(): bool
    {
        return $this->is_active && in_array($this->status, [self::STATUS_ONLINE], true);
    }

    public function isStale(): bool
    {
        $timeout = (int) config('hosting.agent.offline_after', 25);

        return $this->last_heartbeat_at === null
            || $this->last_heartbeat_at->lt(now()->subSeconds($timeout));
    }

    /** Пересчитать статус по heartbeat'ам. Вызывается из schedule. */
    public function refreshStatus(): void
    {
        $was = $this->status;

        if (! $this->is_active) {
            $status = self::STATUS_DISABLED;
        } elseif ($was === self::STATUS_MAINTENANCE) {
            $status = self::STATUS_MAINTENANCE;
        } elseif ($this->isStale()) {
            $status = self::STATUS_OFFLINE;
        } else {
            $status = self::STATUS_ONLINE;
        }

        if ($status !== $was) {
            $this->forceFill([
                'status' => $status,
                'missed_heartbeats' => $status === self::STATUS_OFFLINE ? $this->missed_heartbeats + 1 : 0,
            ])->save();
        }
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_ONLINE => 'green',
            self::STATUS_MAINTENANCE => 'yellow',
            self::STATUS_OFFLINE => 'red',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return __('nodes.status.'.$this->status);
    }

    // ── Ресурсы ─────────────────────────────────────────────────────────

    public function allocatableMemoryMb(): int
    {
        $total = (int) $this->memory_total_mb;
        if ($total <= 0) {
            return PHP_INT_MAX;
        }

        $percent = (int) $this->allocatable_percent;
        $reserved = (int) $this->reserved_memory_mb;
        $allocatable = (int) round($total * $percent / 100) - $reserved;
        $capped = $this->max_memory_mb ? min($allocatable, (int) $this->max_memory_mb) : $allocatable;

        return max(0, $capped);
    }

    public function allocatableDiskMb(): int
    {
        $total = (int) $this->disk_total_mb;
        if ($total <= 0) {
            return PHP_INT_MAX;
        }

        $allocatable = (int) round($total * (int) $this->allocatable_percent / 100);
        $capped = $this->max_disk_mb ? min($allocatable, (int) $this->max_disk_mb) : $allocatable;

        return max(0, $capped);
    }

    public function freeMemoryMb(): int
    {
        $free = $this->allocatableMemoryMb() - (int) $this->used_memory_mb;

        return max(0, $free);
    }

    public function freeDiskMb(): int
    {
        $free = $this->allocatableDiskMb() - (int) $this->used_disk_mb;

        return max(0, $free);
    }

    public function memoryUsagePercent(): float
    {
        $total = (int) $this->allocatableMemoryMb();
        if ($total <= 0) {
            return 0.0;
        }

        return round((int) $this->used_memory_mb / $total * 100, 1);
    }

    public function diskUsagePercent(): float
    {
        $total = (int) $this->allocatableDiskMb();
        if ($total <= 0) {
            return 0.0;
        }

        return round((int) $this->used_disk_mb / $total * 100, 1);
    }

    public function runningCount(): int
    {
        return $this->servers()->where('status', 'running')->count();
    }

    public function serverCount(): int
    {
        return $this->servers()->count();
    }

    /** Поддерживает ли нода конкретный рантайм. */
    public function supportsRuntime(string $runtime): bool
    {
        $available = $this->runtimes_available;

        if (is_array($available) && $available !== []) {
            return in_array($runtime, $available, true);
        }

        return $this->runtime === $runtime;
    }

    public function runtimes(): array
    {
        return $this->runtimes_available ?: [$this->runtime];
    }

    // ── Скоупы ──────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', self::STATUS_ONLINE)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('hosting.agent.offline_after', 25)));
    }

    public function scopeByRegion(Builder $query, ?string $region): Builder
    {
        return $region ? $query->where('region', $region) : $query;
    }

    public function flagEmoji(): string
    {
        if (blank($this->country)) {
            return '🌐';
        }

        $code = strtoupper($this->country);
        $offset = 127397;
        $emoji = '';

        foreach (str_split($code) as $char) {
            $emoji .= mb_chr(mb_ord($char) + $offset, 'UTF-8');
        }

        return $emoji;
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->flagEmoji() === '🌐' ? '' : $this->flagEmoji().' ').$this->name));
    }

    /** Процент доступности ноды за последние N дней (по журналу health-логов). */
    public function uptimePercent(int $days = 1): float
    {
        $logs = $this->healthLogs()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_online = 0 THEN 1 ELSE 0 END) as down')
            ->first();

        $total = (int) ($logs->total ?? 0);
        $down = (int) ($logs->down ?? 0);

        if ($total === 0) {
            return 100.0;
        }

        return round(($total - $down) / $total * 100, 2);
    }

    public function isUsableFor(Server $server): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        if ($this->serverCount() >= (int) $this->max_servers) {
            return false;
        }

        if (! $this->supportsRuntime((string) $server->runtime)) {
            return false;
        }

        return $this->freeMemoryMb() >= (int) $server->memory_mb
            && $this->freeDiskMb() >= (int) $server->disk_mb;
    }

    public function heartbeatAgeSeconds(): ?int
    {
        return $this->last_heartbeat_at
            ? (int) Carbon::parse($this->last_heartbeat_at)->diffInSeconds(now())
            : null;
    }
}
