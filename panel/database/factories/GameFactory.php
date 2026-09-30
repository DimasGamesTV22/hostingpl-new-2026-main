<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => Str::title(fake()->words(2, true)),
            'family' => 'custom',
            'short_description' => fake()->sentence(6),
            'startup' => [
                'exec' => 'sh',
                'args' => ['start.sh'],
                'cwd' => '.',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
            ],
            'installer' => [
                'type' => 'none',
                'timeout' => 1800,
            ],
            'image' => 'gamedock/'.$slug.':latest',
            'working_user' => 'gamedock',
            'uses_steamcmd' => false,
            'min_slots' => 1,
            'max_slots' => 1000,
            'default_slots' => 10,
            'slot_step' => 1,
            'price_per_slot_month' => 0,
            'default_memory_mb' => 2048,
            'min_memory_mb' => 512,
            'default_cpu_percent' => 50,
            'default_disk_mb' => 10240,
            'supports_rcon' => false,
            'supports_query' => true,
            'supports_bedrock' => false,
            'supports_plugins' => false,
            'supports_auto_update' => false,
            'supports_custom_builds' => false,
            'supports_cron' => false,
            'is_custom' => true,
            'is_active' => true,
            'is_public' => true,
            'is_featured' => false,
            'sort' => 100,
        ];
    }

    public function slug(string $slug): static
    {
        return $this->state(fn () => [
            'slug' => $slug,
            'image' => 'gamedock/'.$slug.':latest',
        ]);
    }

    public function family(string $family): static
    {
        return $this->state(fn () => ['family' => $family]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true, 'sort' => 1]);
    }

    public function withQuery(string $type = 'minecraft'): static
    {
        return $this->state(fn () => [
            'supports_query' => true,
            'startup' => [
                'exec' => 'java',
                'args' => ['-Xms1G', '-Xmx2G', 'jar', 'server.jar'],
                'cwd' => '.',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
                'query' => ['type' => $type, 'port_from' => 'query_port'],
                'rcon' => ['type' => 'minecraft', 'port_from' => 'rcon_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],
        ]);
    }
}
