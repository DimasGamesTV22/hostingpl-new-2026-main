<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use Database\Factories\Concerns\ForceFillsAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    use ForceFillsAttributes;

    protected $model = Node::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'name' => 'Нода '.fake()->word(),
            'slug' => $slug,
            'description' => 'Тестовая нода',
            'connection_mode' => 'inbound',
            'agent_port' => 9222,
            'tls' => false,
            'country' => 'RU',
            'region' => 'eu',
            'timezone' => 'Europe/Moscow',
            'flagship' => $slug.'.example.com',
            'runtime' => 'docker',
            'runtimes_available' => ['docker'],
            'max_servers' => 50,
            'max_memory_mb' => 65536,
            'max_disk_mb' => 1048576,
            'max_cpu_percent' => 4000,
            'allocatable_percent' => 85,
            'reserved_memory_mb' => 1024,
            'status' => Node::STATUS_OFFLINE,
            'weight' => 100,
            'region_priority' => 50,
            'is_active' => true,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status' => Node::STATUS_ONLINE,
            'last_heartbeat_at' => now(),
            'cpu_cores' => 8,
            'cpu_threads' => 16,
            'memory_total_mb' => 65536,
            'disk_total_mb' => 2097152,
            'disk_free_mb' => 1900000,
            'network_mbps' => 1000,
            'os' => 'Debian 13',
            'agent_version' => '1.0.0',
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => [
            'status' => Node::STATUS_MAINTENANCE,
            'is_active' => false,
            'status_message' => 'Плановые работы',
        ]);
    }

    public function runtime(string $runtime): static
    {
        return $this->state(fn () => ['runtime' => $runtime, 'runtimes_available' => [$runtime]]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function withRegion(string $region, int $priority = 50): static
    {
        return $this->state(fn () => ['region' => $region, 'region_priority' => $priority]);
    }
}
