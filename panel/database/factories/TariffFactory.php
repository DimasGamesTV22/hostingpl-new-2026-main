<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => 'Тариф '.fake()->word(),
            'short_description' => fake()->sentence(6),
            'model' => Tariff::MODEL_PACKAGE,
            'price' => 100,
            'billing_period' => 'month',
            'duration_days' => 30,
            'slots' => 10,
            'extra_slots' => 0,
            'memory_mb' => 1024,
            'extra_memory_mb' => 0,
            'cpu_percent' => 50,
            'extra_cpu_percent' => 0,
            'disk_mb' => 10240,
            'extra_disk_mb' => 0,
            'network_mbps' => 25,
            'pids' => 512,
            'backups' => 3,
            'max_servers' => 1,
            'allow_sub_accounts' => true,
            'allow_custom_port' => false,
            'priority_support' => false,
            'allow_console' => true,
            'allow_scheduler' => true,
            'allow_file_manager' => true,
            'allow_rcon' => false,
            'private' => false,
            'is_trial' => false,
            'is_active' => true,
            'is_public' => true,
            'sort' => 0,
        ];
    }

    public function model(string $model): static
    {
        return $this->state(fn () => ['model' => $model]);
    }

    public function slots(int $slots): static
    {
        return $this->state(fn () => ['slots' => $slots]);
    }

    public function price(float|int|string $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function trial(): static
    {
        return $this->state(fn () => [
            'is_trial' => true,
            'price' => 0,
            'max_servers' => 1,
        ]);
    }

    public function withRcon(): static
    {
        return $this->state(fn () => ['allow_rcon' => true, 'allow_custom_port' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
