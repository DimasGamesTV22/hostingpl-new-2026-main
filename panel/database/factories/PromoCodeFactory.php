<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(8)),
            'name' => 'Промокод',
            'type' => PromoCode::TYPE_DISCOUNT,
            'percent' => 10,
            'used_count' => 0,
            'is_active' => true,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn () => ['code' => Str::upper($code)]);
    }

    public function percent(int $percent): static
    {
        return $this->state(fn () => [
            'type' => PromoCode::TYPE_DISCOUNT,
            'percent' => $percent,
        ]);
    }

    public function fixedAmount(int $amount): static
    {
        return $this->state(fn () => [
            'type' => PromoCode::TYPE_DISCOUNT,
            'amount' => $amount,
            'percent' => 0,
        ]);
    }

    public function duration(int $days): static
    {
        return $this->state(fn () => [
            'type' => PromoCode::TYPE_DURATION,
            'days' => $days,
        ]);
    }

    public function bonusRub(float|int|string $rub): static
    {
        return $this->state(fn () => [
            'type' => PromoCode::TYPE_BONUS,
            'bonus_rub' => $rub,
        ]);
    }

    public function bonusSlots(int $slots): static
    {
        return $this->state(fn () => [
            'type' => PromoCode::TYPE_BONUS,
            'bonus_slots' => $slots,
        ]);
    }

    public function withTotalLimit(int $uses): static
    {
        return $this->state(fn () => ['max_uses' => $uses]);
    }

    public function withPerUserLimit(int $limit): static
    {
        return $this->state(fn () => ['per_user_limit' => $limit]);
    }

    public function withMinOrder(float|int|string $min): static
    {
        return $this->state(fn () => ['min_order' => $min]);
    }

    public function onlyForGame(int $gameId): static
    {
        return $this->state(fn () => ['applies_to_games' => [$gameId]]);
    }

    public function onlyForTariff(int $tariffId): static
    {
        return $this->state(fn () => ['applies_to_tariffs' => [$tariffId]]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['valid_until' => now()->subDay()]);
    }

    public function notYetValid(): static
    {
        return $this->state(fn () => ['valid_from' => now()->addDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
