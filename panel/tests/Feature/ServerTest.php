<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Node;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Модель сервера: статусы, сроки аренды, квоты и скоупы выборки.
 */
class ServerTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(array $attrs = []): Server
    {
        return Server::factory()
            ->ownedBy(
                User::factory()->create(),
                Game::factory()->create(),
                Node::factory()->create(),
                Tariff::factory()->create(),
            )
            ->create($attrs);
    }

    public function test_uuid_is_generated_on_create(): void
    {
        $server = $this->makeServer();

        $this->assertNotEmpty($server->uuid);
        $this->assertSame(36, strlen($server->uuid));
    }

    public function test_status_change_stamps_status_changed_at(): void
    {
        $server = $this->makeServer(['status' => Server::STATUS_STOPPED]);
        $before = $server->status_changed_at;

        $server->forceFill(['status' => Server::STATUS_RUNNING])->save();

        $this->assertNotNull($server->status_changed_at);
        $this->assertNotSame((string) $before, (string) $server->status_changed_at);
    }

    public function test_pending_server_cannot_start(): void
    {
        $server = Server::factory()->pending()->create();

        $this->assertTrue($server->isInstalling());
        $this->assertFalse($server->isInstalled());
        $this->assertFalse($server->canStart());
    }

    public function test_installed_stopped_server_can_start(): void
    {
        $server = Server::factory()->stopped()->create();

        $this->assertTrue($server->isInstalled());
        $this->assertTrue($server->canStart());
        $this->assertFalse($server->canStop());
    }

    public function test_running_server_can_stop_but_not_start(): void
    {
        $server = Server::factory()->running()->create();

        $this->assertTrue($server->isRunning());
        $this->assertTrue($server->canStop());
        $this->assertFalse($server->canStart());
    }

    public function test_suspended_server_cannot_start(): void
    {
        $server = Server::factory()->stopped()->suspended()->create();

        $this->assertTrue($server->isSuspended());
        $this->assertFalse($server->canStart());
    }

    public function test_frozen_server_cannot_start(): void
    {
        $server = Server::factory()->stopped()->create();
        $server->forceFill(['is_frozen' => true])->save();

        $this->assertTrue($server->isSuspended());
        $this->assertFalse($server->canStart());
    }

    public function test_crashed_server_can_be_stopped(): void
    {
        $server = Server::factory()->crashed()->create();

        $this->assertTrue($server->canStop());
    }

    public function test_expiry_and_grace_period(): void
    {
        $server = $this->makeServer(['status' => Server::STATUS_STOPPED]);
        $server->forceFill(['expires_at' => now()->addDays(10)])->save();

        $this->assertFalse($server->isExpired());
        $this->assertFalse($server->inGrace());

        $server->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertTrue($server->isExpired());
        $this->assertTrue($server->inGrace(), '3 дня после окончания — льготный период');

        $server->forceFill(['expires_at' => now()->subDays(30)])->save();
        $this->assertFalse($server->inGrace());
    }

    public function test_expiring_never_is_not_expired(): void
    {
        $server = $this->makeServer();
        $server->forceFill(['expires_at' => null])->save();

        $this->assertFalse($server->isExpired());
        $this->assertSame('∞', $server->expiresInHuman());
    }

    public function test_expires_in_human_is_coarse(): void
    {
        $server = $this->makeServer();

        $server->forceFill(['expires_at' => now()->addMinutes(30)])->save();
        $this->assertStringEndsWith('мин', $server->expiresInHuman());

        $server->forceFill(['expires_at' => now()->addHours(5)])->save();
        $this->assertStringEndsWith('ч', $server->expiresInHuman());

        $server->forceFill(['expires_at' => now()->addDays(5)])->save();
        $this->assertStringEndsWith('дн', $server->expiresInHuman());

        $server->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertNotSame('∞', $server->expiresInHuman());
    }

    public function test_grace_ends_at_is_null_without_expiry(): void
    {
        $server = $this->makeServer();

        $this->assertNull($server->graceEndsAt());
    }

    public function test_resource_percentages(): void
    {
        $server = $this->makeServer(['memory_mb' => 2048, 'slots' => 20]);
        $server->forceFill([
            'memory_usage_mb' => 512,
            'base_slots' => 10,
            'players_online' => 5,
        ])->save();

        $this->assertSame(25.0, $server->memoryUsagePercent());
        $this->assertSame(25.0, $server->playersPercent());
        $this->assertSame(10, $server->extraSlots());
    }

    public function test_percentages_are_zero_without_baseline(): void
    {
        $server = $this->makeServer();
        $server->forceFill(['memory_mb' => 0, 'slots' => 0, 'base_slots' => 0])->save();

        $this->assertSame(0.0, $server->memoryUsagePercent());
        $this->assertSame(0.0, $server->playersPercent());
        $this->assertSame(0, $server->extraSlots());
    }

    public function test_online_players_attribute_reflects_status(): void
    {
        $running = Server::factory()->running()->create();
        $stopped = Server::factory()->stopped()->create();

        $this->assertStringContainsString('/', (string) $running->online_players);
        $this->assertSame('—', (string) $stopped->online_players);
    }

    public function test_name_is_trimmed_and_capped(): void
    {
        $server = $this->makeServer(['name' => '   '.str_repeat('я', 200).'   ']);

        $this->assertSame(80, mb_strlen($server->fresh()->name));
        $this->assertFalse(str_starts_with($server->fresh()->name, ' '));
    }

    public function test_env_is_hidden_from_serialization(): void
    {
        $server = $this->makeServer();

        $this->assertArrayNotHasKey('env', $server->toArray());
    }

    // ── Скоупы ──────────────────────────────────────────────────────────

    public function test_active_scope_excludes_deleted_servers(): void
    {
        $alive = $this->makeServer(['status' => Server::STATUS_STOPPED]);
        $deleting = $this->makeServer(['status' => Server::STATUS_DELETING]);

        $ids = Server::active()->pluck('id')->all();

        $this->assertContains($alive->id, $ids);
        $this->assertNotContains($deleting->id, $ids);
    }

    public function test_for_user_scope_isolates_owners(): void
    {
        $mine = Server::factory()->forUser(User::factory()->create())->create([
            'game_id' => Game::factory()->create()->id,
        ]);
        $theirs = Server::factory()->forUser(User::factory()->create())->create([
            'game_id' => Game::factory()->create()->id,
        ]);

        $ids = Server::forUser($mine->user_id)->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_expiring_scope_picks_soon_to_expire(): void
    {
        $soon = $this->makeServer();
        $soon->forceFill(['expires_at' => now()->addDay()])->save();

        $later = $this->makeServer();
        $later->forceFill(['expires_at' => now()->addYear()])->save();

        $expiredLongAgo = $this->makeServer();
        $expiredLongAgo->forceFill(['expires_at' => now()->subYear()])->save();

        $ids = Server::expiring()->pluck('id')->all();

        $this->assertContains($soon->id, $ids);
        $this->assertNotContains($later->id, $ids);
        $this->assertNotContains($expiredLongAgo->id, $ids, 'давно истёкшие не «скоро истекут»');
    }

    public function test_scheduled_for_charge_scope_excludes_transitional(): void
    {
        $running = $this->makeServer(['status' => Server::STATUS_RUNNING]);
        $installing = $this->makeServer(['status' => Server::STATUS_INSTALLING]);

        $running->forceFill(['expires_at' => now()->subHour()])->save();
        $installing->forceFill(['expires_at' => now()->subHour()])->save();

        $ids = Server::scheduledForCharge()->pluck('id')->all();

        $this->assertContains($running->id, $ids);
        $this->assertNotContains($installing->id, $ids);
    }

    public function test_purgable_scope_uses_purge_at(): void
    {
        $server = $this->makeServer();
        $server->forceFill(['purge_at' => now()->subDay()])->save();

        $fresh = $this->makeServer();
        $fresh->forceFill(['purge_at' => now()->addDay()])->save();

        $ids = Server::purgable()->pluck('id')->all();

        $this->assertContains($server->id, $ids);
        $this->assertNotContains($fresh->id, $ids);
    }

    public function test_soft_deletes_are_hidden_by_default(): void
    {
        $server = $this->makeServer();
        $server->delete();

        $this->assertNull(Server::find($server->id));
        $this->assertNotNull(Server::withTrashed()->find($server->id));
    }

    public function test_uptime_human(): void
    {
        $server = $this->makeServer();

        $this->assertSame('—', $server->uptimeHuman());

        $server->forceFill(['uptime_seconds' => 3661])->save();
        $this->assertNotSame('—', $server->uptimeHuman());
    }

    public function test_in_network_prefers_stored_address(): void
    {
        $server = $this->makeServer();
        $server->forceFill(['address' => 'play.example.com:25000', 'game_port' => 25000])->save();

        $this->assertSame('play.example.com:25000', $server->inNetwork());
        $this->assertSame('play.example.com:25000', $server->connectAddress());
    }

    public function test_status_label_and_colour_are_translated(): void
    {
        $server = $this->makeServer(['status' => Server::STATUS_RUNNING]);

        $this->assertNotSame('', $server->statusLabel());
        $this->assertSame('green', $server->statusColor());
    }

    public function test_transitional_statuses(): void
    {
        foreach ([Server::STATUS_PENDING, Server::STATUS_STARTING, Server::STATUS_STOPPING] as $status) {
            $server = $this->makeServer(['status' => $status]);
            $this->assertTrue($server->isTransitional(), $status.' должен быть переходным');
        }

        $server = $this->makeServer(['status' => Server::STATUS_RUNNING]);
        $this->assertFalse($server->isTransitional());
    }
}
