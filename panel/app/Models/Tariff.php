<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tariff extends Model
{
    use HasFactory;

    public const MODEL_PACKAGE = 'package';
    public const MODEL_SLOTS = 'slots';
    public const MODEL_HYBRID = 'hybrid';

    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';

    protected $fillable = [
        'slug', 'name', 'description', 'short_description', 'model',
        'price', 'billing_period', 'duration_days',
        'slots', 'extra_slots', 'memory_mb', 'extra_memory_mb',
        'cpu_percent', 'extra_cpu_percent', 'disk_mb', 'extra_disk_mb',
        'network_mbps', 'pids', 'backups', 'max_servers',
        'allow_sub_accounts', 'allow_custom_port', 'priority_support',
        'allow_console', 'allow_scheduler', 'allow_file_manager', 'allow_rcon',
        'private', 'features', 'badge', 'accent',
        'is_trial', 'is_active', 'is_public', 'is_popular', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'is_trial' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_popular' => 'boolean',
            'private' => 'boolean',
            'allow_sub_accounts' => 'boolean',
            'allow_custom_port' => 'boolean',
            'priority_support' => 'boolean',
            'allow_console' => 'boolean',
            'allow_scheduler' => 'boolean',
            'allow_file_manager' => 'boolean',
            'allow_rcon' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tariff) {
            if (blank($tariff->slug)) {
                $tariff->slug = Str::slug($tariff->name);
            }
        });
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('price');
    }

    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('is_trial', true);
    }

    // ── Расчёты ─────────────────────────────────────────────────────────

    public function periodDays(): int
    {
        return match ($this->billing_period) {
            self::PERIOD_DAY => 1,
            self::PERIOD_WEEK => 7,
            self::PERIOD_YEAR => 365,
            default => 30,
        };
    }

    /** Базовая цена периода. */
    public function basePrice(): float
    {
        return round((float) $this->price, 2);
    }

    /** Цена в день (для расчёта посуточных начислений). */
    public function dailyPrice(): float
    {
        return round($this->basePrice() / max(1, $this->periodDays()), 2);
    }

    /**
     * Итоговая цена за период с учётом модели и докупок.
     *
     * @param  array{slots?:int, extra_slots?:int, memory_mb?:int, disk_mb?:int, cpu_percent?:int, quantity?:array<string,int>}  $options
     */
    public function calculatePrice(array $options = []): float
    {
        if ($this->model === self::MODEL_SLOTS) {
            $slots = max(0, (int) ($options['slots'] ?? $this->slots));
            $perSlot = $this->prices->firstWhere('resource', 'extra_slots');
            $rate = $perSlot ? (float) $perSlot->price : (float) $this->price;

            return round($slots * $rate, 2);
        }

        $total = $this->basePrice();

        if ($this->model === self::MODEL_HYBRID) {
            $total += $this->extrasTotal($options);
        }

        return round($total, 2);
    }

    /** Стоимость докупок по таблице tariff_prices. */
    public function extrasTotal(array $options = []): float
    {
        $total = 0.0;

        foreach ($this->prices as $price) {
            $qty = (int) ($options['quantity'][$price->resource] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unit = max(1, (int) $price->unit_quantity);
            $units = (int) ceil($qty / $unit);
            $total += $units * (float) $price->price;
        }

        return round($total, 2);
    }

    public function priceFor(string $resource, int $quantity = 1): float
    {
        $price = $this->prices->firstWhere('resource', $resource);
        if (! $price) {
            return 0.0;
        }

        $unit = max(1, (int) $price->unit_quantity);

        return round((int) ceil($quantity / $unit) * (float) $price->price, 2);
    }

    public function priceForSlots(int $slots): float
    {
        $price = $this->prices->firstWhere('resource', 'extra_slots');

        return $price ? round($slots * (float) $price->price, 2) : 0.0;
    }

    /** Сколько серверов разрешено по тарифу (0 = без ограничений тарифом). */
    public function serverLimit(int $fallback): int
    {
        return $this->max_servers > 0 ? (int) $this->max_servers : $fallback;
    }

    public function memoryLimitMb(): int
    {
        return (int) $this->memory_mb;
    }

    public function slotLimit(): int
    {
        return (int) $this->slots;
    }

    public function formattedPrice(): string
    {
        if ($this->model === self::MODEL_SLOTS && (float) $this->price === 0.0) {
            return 'от '.number_format($this->priceForSlots(1), 0).' '.config('hosting.billing.currency_symbol').'/слот';
        }

        return number_format($this->basePrice(), config('hosting.billing.round_decimals')).' '.config('hosting.billing.currency_symbol');
    }

    public function periodLabel(): string
    {
        return match ($this->billing_period) {
            self::PERIOD_DAY => '/день',
            self::PERIOD_WEEK => '/неделю',
            self::PERIOD_YEAR => '/год',
            default => '/мес',
        };
    }

    public function accentColor(): string
    {
        return $this->accent ?: 'brand';
    }
}
