<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Crypto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\CarbonInterval;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_CRASHED = 'crashed';
    public const STATUS_ERROR = 'error';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETING = 'deleting';
    public const STATUS_DELETED = 'deleted';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_INSTALLING, self::STATUS_INSTALLED,
        self::STATUS_STARTING, self::STATUS_RUNNING, self::STATUS_STOPPING,
        self::STATUS_STOPPED, self::STATUS_CRASHED, self::STATUS_ERROR,
        self::STATUS_SUSPENDED, self::STATUS_DELETING, self::STATUS_DELETED,
    ];

    /** Статусы, в которых сервер считается «живым» для публичного мониторинга. */
    public const ALIVE_STATUSES = [self::STATUS_RUNNING];

    public const TRANSITIONAL_STATUSES = [
        self::STATUS_PENDING, self::STATUS_INSTALLING,
        self::STATUS_STARTING, self::STATUS_STOPPING, self::STATUS_DELETING,
    ];

    protected $fillable = [
        'user_id', 'game_id', 'node_id', 'tariff_id', 'name', 'runtime',
        'status', 'startup', 'env', 'config_values', 'install_command', 'build_version',
        'memory_mb', 'cpu_percent', 'swap_mb', 'disk_mb', 'network_mbps', 'pids', 'slots',
        'watchdog_enabled', 'sub_accounts_enabled',
    ];

    protected $hidden = ['env'];

    protected function casts(): array
    {
        return [
            'startup' => 'array',
            'env' => 'array',
            'config_values' => 'array',
            'install_manifest' => 'array',
            'watchdog_enabled' => 'boolean',
            'sub_accounts_enabled' => 'boolean',
            'is_frozen' => 'boolean',
            'expires_at' => 'datetime',
            'last_started_at' => 'datetime',
            'last_stopped_at' => 'datetime',
            'last_crash_at' => 'datetime',
            'suspended_at' => 'datetime',
            'auto_stop_at' => 'datetime',
            'purge_at' => 'datetime',
            'installed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'last_install_attempt_at' => 'datetime',
            'metrics_at' => 'datetime',
            'cpu_usage' => 'float',
            'uptime_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $server) {
            $server->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $server) {
            if ($server->isDirty('status') && ! $server->isDirty('status_changed_at')) {
                $server->status_changed_at = now();
            }
        });
    }

    // ── Отношения ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServerEvent::class);
    }

    public function installLogs(): HasMany
    {
        return $this->hasMany(ServerInstallLog::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ServerSnapshot::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ServerSchedule::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(ServerCommand::class);
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(ServerSubAccount::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ServerCharge::class);
    }

    public function templateInstalls(): HasMany
    {
        return $this->hasMany(GameTemplateInstall::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MetricHourly::class);
    }

    public function chargesWithPending(): HasMany
    {
        return $this->hasMany(ServerCharge::class)->where('status', 'pending');
    }

    // ── Статус ──────────────────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isInstalling(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_INSTALLING], true);
    }

    public function isTransitional(): bool
    {
        return in_array($this->status, self::TRANSITIONAL_STATUSES, true);
    }

    public function isInstalled(): bool
    {
        return $this->installed_at !== null && ! $this->isInstalling();
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED || $this->is_frozen;
    }

    public function canStart(): bool
    {
        return $this->isInstalled()
            && ! $this->isSuspended()
            && ! in_array($this->status, [self::STATUS_RUNNING, self::STATUS_STARTING, self::STATUS_STOPPING], true);
    }

    public function canStop(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_STARTING, self::STATUS_CRASHED], true);
    }

    public function statusLabel(): string
    {
        return __('servers.status.'.$this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'green',
            self::STATUS_STARTING, self::STATUS_STOPPING, self::STATUS_INSTALLING,
            self::STATUS_PENDING => 'yellow',
            self::STATUS_STOPPED, self::STATUS_INSTALLED, self::STATUS_SUSPENDED => 'gray',
            self::STATUS_CRASHED, self::STATUS_ERROR, self::STATUS_DELETING => 'red',
            default => 'gray',
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresInHuman(): string
    {
        if ($this->expires_at === null) {
            return '∞';
        }

        $diff = now()->diffInSeconds($this->expires_at, false);

        return match (true) {
            $diff <= 0 => __('servers.expired'),
            $diff < 3600 => intdiv($diff, 60).' мин',
            $diff < 86400 => intdiv($diff, 3600).' ч',
            default => intdiv($diff, 86400).' дн',
        };
    }

    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        $days = (int) config('hosting.billing.grace_period_days', 3);

        return $this->expires_at ? $this->expires_at->copy()->addDays($days) : null;
    }

    public function inGrace(): bool
    {
        $grace = $this->graceEndsAt();

        return $grace !== null && now()->isBefore($grace);
    }

    // ── Порты и адреса ──────────────────────────────────────────────────

    public function connectAddress(): ?string
    {
        if (! $this->address) {
            return null;
        }

        return $this->address;
    }

    public function rconPassword(): ?string
    {
        // RCON-пароль генерируется при создании и хранится в зашифрованном env
        $password = $this->env['RCON_PASSWORD'] ?? $this->env['rcon_password'] ?? null;

        return $password ? Crypto::decrypt($password) : null;
    }

    public function hasPortAvailable(): bool
    {
        return $this->game_port !== null;
    }

    public function inNetwork(): string
    {
        if ($this->address) {
            return $this->address;
        }

        $ip = $this->node?->flagship ?: ($this->node?->host ?: '—');
        $port = $this->game_port ? ':'.$this->game_port : '';

        return $ip.$port;
    }

    // ── Ресурсы ─────────────────────────────────────────────────────────

    public function memoryUsagePercent(): float
    {
        return $this->memory_mb > 0
            ? round($this->memory_usage_mb / $this->memory_mb * 100, 1)
            : 0.0;
    }

    public function playersPercent(): float
    {
        return $this->slots > 0
            ? round($this->players_online / $this->slots * 100, 1)
            : 0.0;
    }

    public function extraSlots(): int
    {
        return max(0, (int) $this->slots - (int) $this->base_slots);
    }

    public function uptimeHuman(): string
    {
        $seconds = (int) $this->uptime_seconds;
        if ($seconds <= 0) {
            return '—';
        }

        return CarbonInterval::make($seconds)->forHumans(['short' => true, 'parts' => 3]);
    }

    // ── Скоупы ──────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_DELETING, self::STATUS_DELETED]);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeExpiring(Builder $query, ?int $minutes = null): Builder
    {
        $minutes ??= (int) config('hosting.billing.warn_before_expiry_days', 3) * 24 * 60;

        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addMinutes($minutes))
            ->where('expires_at', '>', now()->subDays(30));
    }

    public function scopeScheduledForCharge(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', [self::STATUS_RUNNING, self::STATUS_STOPPED, self::STATUS_INSTALLED, self::STATUS_CRASHED, self::STATUS_STARTING]);
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function scopePurgable(Builder $query): Builder
    {
        return $query->whereNotNull('purge_at')->where('purge_at', '<=', now());
    }

    // ── Атрибуты ────────────────────────────────────────────────────────

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => mb_substr(trim((string) $value), 0, 80),
        );
    }

    protected function onlinePlayers(): Attribute
    {
        return Attribute::get(fn (): string => $this->isRunning()
            ? $this->players_online.'/'.$this->slots
            : '—');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()->where('id', (int) $value)->first();
    }
}
