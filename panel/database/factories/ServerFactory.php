<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Game;
use App\Models\Node;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use Database\Factories\Concerns\ForceFillsAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    use ForceFillsAttributes;

    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word().' server',
            'runtime' => 'docker',
            'status' => Server::STATUS_STOPPED,
            'startup' => [
                'exec' => 'sh',
                'args' => ['start.sh'],
                'cwd' => '.',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
            ],
            'env' => [],
            'config_values' => [],
            'memory_mb' => 2048,
            'cpu_percent' => 50,
            'swap_mb' => 0,
            'disk_mb' => 10240,
            'network_mbps' => 25,
            'pids' => 512,
            'slots' => 10,
            'base_slots' => 10,
            'watchdog_enabled' => true,
            'sub_accounts_enabled' => false,
        ];
    }

    /** Сервер, полностью привязанный к пользователю/игре/ноде/тарифу. */
    public function ownedBy(
        ?User $user = null,
        ?Game $game = null,
        ?Node $node = null,
        ?Tariff $tariff = null,
    ): static {
        $user ??= User::factory()->create();
        $game ??= Game::factory()->create();
        $node ??= Node::factory()->create();
        $tariff ??= Tariff::factory()->create();

        return $this->state(fn () => [
            'user_id' => $user->id,
            'game_id' => $game->id,
            'node_id' => $node->id,
            'tariff_id' => $tariff->id,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function onNode(Node $node): static
    {
        return $this->state(fn () => ['node_id' => $node->id]);
    }

    public function withGame(Game $game): static
    {
        return $this->state(fn () => ['game_id' => $game->id]);
    }

    public function withTariff(Tariff $tariff): static
    {
        return $this->state(fn () => ['tariff_id' => $tariff->id]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => Server::STATUS_RUNNING,
            'installed_at' => now()->subHour(),
            'last_started_at' => now()->subMinutes(30),
            'uptime_seconds' => 1800,
            'players_online' => 3,
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn () => [
            'status' => Server::STATUS_STOPPED,
            'installed_at' => now()->subDay(),
            'uptime_seconds' => 0,
            'players_online' => 0,
        ]);
    }

    /** Ещё не установлен — агент ставит игру. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => Server::STATUS_PENDING,
            'installed_at' => null,
        ]);
    }

    public function crashed(): static
    {
        return $this->state(fn () => [
            'status' => Server::STATUS_CRASHED,
            'installed_at' => now()->subDay(),
            'last_crash_at' => now()->subMinutes(5),
        ]);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['expires_at' => now()->addDays($days)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function expiringNever(): static
    {
        return $this->state(fn () => ['expires_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => Server::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
    }

    public function withPorts(int $game, ?int $query = null, ?int $rcon = null): static
    {
        return $this->state(fn () => [
            'game_port' => $game,
            'query_port' => $query,
            'rcon_port' => $rcon,
            'address' => 'node.example.com:'.$game,
            'query_address' => $query ? 'node.example.com:'.$query : null,
        ]);
    }

    public function withSubAccountsEnabled(): static
    {
        return $this->state(fn () => ['sub_accounts_enabled' => true]);
    }
}
