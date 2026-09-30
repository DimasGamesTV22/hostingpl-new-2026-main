<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PromoCode extends Model
{
    use HasFactory;

    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_DURATION = 'duration';
    public const TYPE_BONUS = 'bonus';

    public const TYPES = [self::TYPE_DISCOUNT, self::TYPE_DURATION, self::TYPE_BONUS];

    protected $table = 'promo_codes';

    protected $fillable = [
        'code', 'name', 'type', 'percent', 'amount', 'days',
        'bonus_rub', 'bonus_slots', 'bonus_memory_mb', 'bonus_disk_mb', 'bonus_days',
        'max_uses', 'used_count', 'per_user_limit', 'min_order', 'max_discount',
        'applies_to_tariffs', 'applies_to_games', 'applies_to_products',
        'first_payment_only', 'allow_stacking', 'valid_from', 'valid_until',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'amount' => 'decimal:2',
            'bonus_rub' => 'decimal:2',
            'min_order' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'days' => 'integer',
            'bonus_slots' => 'integer',
            'bonus_memory_mb' => 'integer',
            'bonus_disk_mb' => 'integer',
            'bonus_days' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'per_user_limit' => 'integer',
            'first_payment_only' => 'boolean',
            'allow_stacking' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'applies_to_tariffs' => 'array',
            'applies_to_games' => 'array',
            'applies_to_products' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $promo) {
            $promo->code = strtoupper(trim((string) $promo->code));
        });
    }

    public function uses(): HasMany
    {
        return $this->hasMany(PromoUse::class, 'promo_code_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    // ── Проверки ────────────────────────────────────────────────────────

    public function isValid(): bool
    {
        return $this->isUsableBy(null, 0.0)['ok'];
    }

    public function validityProblem(): ?string
    {
        $r = $this->isUsableBy(null, 0.0);

        return $r['ok'] ? null : $r['reason'];
    }

    /**
     * Проверка применимости промокода.
     *
     * @return array{ok: bool, reason: ?string, discount: float, days: int, bonus: array<string, float|int>}
     */
    public function isUsableBy(?User $user, float $orderAmount, ?Server $server = null, ?string $product = null): array
    {
        $fail = static fn (string $reason) => [
            'ok' => false, 'reason' => $reason, 'discount' => 0.0, 'days' => 0, 'bonus' => [],
        ];

        if (! $this->is_active) {
            return $fail(__('promo.errors.inactive'));
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return $fail(__('promo.errors.not_started'));
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return $fail(__('promo.errors.expired'));
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return $fail(__('promo.errors.used_up'));
        }

        if ($user) {
            $userUses = $this->uses()->where('user_id', $user->id)->count();
            // null = без ограничений; сравнивать с null нельзя (0 >= null истинно)
            if ($this->per_user_limit !== null && $userUses >= $this->per_user_limit) {
                return $fail(__('promo.errors.already_used'));
            }

            if ($this->first_payment_only && $user->total_deposited > 0) {
                return $fail(__('promo.errors.first_payment_only'));
            }
        }

        if ((float) $orderAmount < (float) $this->min_order) {
            return $fail(__('promo.errors.min_order', ['amount' => $this->min_orderFormatted()]));
        }

        if ($this->applies_to_tariffs && $server?->tariff_id && ! in_array($server->tariff_id, $this->applies_to_tariffs)) {
            return $fail(__('promo.errors.not_applicable'));
        }

        if ($this->applies_to_games && $server?->game_id && ! in_array($server->game_id, $this->applies_to_games)) {
            return $fail(__('promo.errors.not_applicable'));
        }

        if ($this->applies_to_products && $product && ! in_array($product, $this->applies_to_products)) {
            return $fail(__('promo.errors.not_applicable'));
        }

        $discount = 0.0;
        if ($this->type === self::TYPE_DISCOUNT) {
            $discount = $this->percent
                ? $orderAmount * $this->percent / 100
                : (float) $this->amount;

            if ($this->max_discount !== null) {
                $discount = min($discount, (float) $this->max_discount);
            }

            $discount = min($discount, $orderAmount);
        }

        return [
            'ok' => true,
            'reason' => null,
            'discount' => round($discount, 2),
            'days' => (int) ($this->days ?? 0),
            'bonus' => array_filter([
                'rub' => (float) ($this->bonus_rub ?? 0),
                'slots' => (int) ($this->bonus_slots ?? 0),
                'memory_mb' => (int) ($this->bonus_memory_mb ?? 0),
                'disk_mb' => (int) ($this->bonus_disk_mb ?? 0),
                'days' => (int) ($this->bonus_days ?? 0),
            ], static fn ($v) => (int) $v !== 0 || (float) $v !== 0.0),
        ];
    }

    // ── Отображение ─────────────────────────────────────────────────────

    public function minOrderFormatted(): string
    {
        return number_format((float) $this->min_order, 0).' '.config('hosting.billing.currency_symbol');
    }

    public function valueLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DISCOUNT => $this->percent ? "−{$this->percent}%" : '−'.number_format((float) $this->amount, 0).' ₽',
            self::TYPE_DURATION => "+{$this->days} дн.",
            self::TYPE_BONUS => $this->bonusLabel(),
            default => '',
        };
    }

    public function bonusLabel(): string
    {
        $parts = [];
        if ((float) $this->bonus_rub > 0) {
            $parts[] = number_format((float) $this->bonus_rub, 0).' ₽';
        }
        if ((int) $this->bonus_slots > 0) {
            $parts[] = $this->bonus_slots.' слотов';
        }
        if ((int) $this->bonus_memory_mb > 0) {
            $parts[] = round($this->bonus_memory_mb / 1024).' ГБ RAM';
        }
        if ((int) $this->bonus_days > 0) {
            $parts[] = $this->bonus_days.' дн.';
        }

        return implode(' + ', $parts);
    }

    public function usesLeft(): ?int
    {
        return $this->max_uses === null ? null : max(0, $this->max_uses - $this->used_count);
    }

    public function progressPercent(): int
    {
        if (! $this->max_uses) {
            return 0;
        }

        return (int) min(100, round($this->used_count / $this->max_uses * 100));
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function statusColor(): string
    {
        if ($this->isExpired()) {
            return 'red';
        }
        if (! $this->is_active) {
            return 'gray';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'yellow';
        }

        return 'green';
    }
}
