<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Секретный код, который игрок вводит в игровом чате.
 * Агент перехватывает строку чата и отправляет панели (см. docs/agent-protocol.md).
 */
class SecretCode extends Model
{
    use HasFactory;

    public const REWARD_MONEY = 'money';
    public const REWARD_SLOTS = 'slots';
    public const REWARD_DAYS = 'days';
    public const REWARD_MEMORY = 'memory';
    public const REWARD_CREDIT = 'credit';

    public const REWARDS = [
        self::REWARD_MONEY, self::REWARD_SLOTS,
        self::REWARD_DAYS, self::REWARD_MEMORY, self::REWARD_CREDIT,
    ];

    protected $fillable = [
        'code', 'hint', 'game_id', 'server_id', 'user_id', 'created_by',
        'reward_type', 'reward_value', 'reward_days', 'reward_memory_mb',
        'max_uses', 'used_count', 'per_player_limit', 'min_rank_level',
        'is_active', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_value' => 'decimal:2',
            'is_active' => 'boolean',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'per_player_limit' => 'integer',
            'min_rank_level' => 'integer',
            'reward_days' => 'integer',
            'reward_memory_mb' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $secret) {
            if (blank($secret->code)) {
                $secret->code = strtoupper(Str::random(8));
            }
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

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function uses(): HasMany
    {
        return $this->hasMany(SecretCodeUse::class, 'secret_code_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Коды, которые агент должен проверять для данного сервера. */
    public function scopeForServer(Builder $query, Server $server): Builder
    {
        return $query->active()
            ->where(function (Builder $q) use ($server) {
                $q->where('server_id', $server->id)
                    ->orWhere(function (Builder $inner) use ($server) {
                        $inner->whereNull('server_id')
                            ->where(function (Builder $g) use ($server) {
                                $g->whereNull('game_id')->orWhere('game_id', $server->game_id);
                            });
                    });
            });
    }

    public function isGlobal(): bool
    {
        return $this->server_id === null;
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    public function canBeUsedBy(?string $playerId): bool
    {
        if (! $this->is_active || $this->isExhausted()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($playerId !== null && $this->per_player_limit > 0) {
            $used = $this->uses()->where('player_id', $playerId)->count();
            if ($used >= $this->per_player_limit) {
                return false;
            }
        }

        return true;
    }

    public function rewardLabel(): string
    {
        return match ($this->reward_type) {
            self::REWARD_MONEY, self::REWARD_CREDIT => number_format((float) $this->reward_value, 0).' ₽',
            self::REWARD_SLOTS => $this->reward_value.' '.trans_choice('слот|слота|слотов', (int) $this->reward_value, [], 'ru'),
            self::REWARD_DAYS => $this->reward_days.' дн. аренды',
            self::REWARD_MEMORY => round($this->reward_memory_mb / 1024).' ГБ RAM',
            default => '',
        };
    }

    public function maskedCode(): string
    {
        $len = strlen($this->code);
        if ($len <= 3) {
            return $this->code;
        }

        return substr($this->code, 0, 2).str_repeat('•', min(6, $len - 4)).substr($this->code, -2);
    }
}
