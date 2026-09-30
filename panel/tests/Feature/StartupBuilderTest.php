<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Node;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Games\StartupBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сборка команды запуска: игра отдаёт шаблон с плейсхолдерами,
 * панель подставляет реальные значения ресурсов и портов.
 */
class StartupBuilderTest extends TestCase
{
    use RefreshDatabase;

    private StartupBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(StartupBuilder::class);
    }

    private function makeServer(array $serverAttrs = [], array $gameAttrs = []): Server
    {
        $node = Node::factory()->create(['flagship' => 'play.example.com']);

        $game = Game::factory()->create(array_merge([
            'startup' => [
                'exec' => 'java',
                'args' => ['-Xms{memory_mb}M', '-Xmx{memory_mb}M', 'jar', 'server.jar', '--port', '{game_port}'],
                'cwd' => '/home/gamedock/server',
                'env' => ['SERVER_NAME' => '{server_name}'],
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 45,
                'query' => ['type' => 'minecraft', 'port_from' => 'query_port'],
            ],
        ], $gameAttrs));

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, $node, Tariff::factory()->create())
            ->create($serverAttrs);

        // Поля вне $fillable проставляем напрямую
        return $server->forceFill([
            'game_port' => 25000,
            'query_port' => 26000,
            'rcon_port' => 27000,
        ]);
    }

    public function test_placeholders_are_replaced_with_real_values(): void
    {
        $result = $this->builder->build($this->makeServer(['memory_mb' => 4096]));

        $this->assertSame('java', $result['startup']['exec']);
        $this->assertContains('-Xms4096M', $result['startup']['args']);
        $this->assertContains('-Xmx4096M', $result['startup']['args']);
        $this->assertContains('25000', $result['startup']['args']);
    }

    public function test_cwd_stop_signal_and_timeout_are_preserved(): void
    {
        $result = $this->builder->build($this->makeServer());

        $this->assertSame('/home/gamedock/server', $result['startup']['cwd']);
        $this->assertSame('SIGTERM', $result['startup']['stop_signal']);
        $this->assertSame(45, $result['startup']['stop_timeout']);
        $this->assertSame(['type' => 'minecraft', 'port_from' => 'query_port'], $result['startup']['query']);
    }

    public function test_unknown_placeholder_is_left_as_is(): void
    {
        $game = Game::factory()->create([
            'startup' => ['exec' => 'sh', 'args' => ['run.sh', '{unknown_thing}']],
        ]);

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, Node::factory()->create(), Tariff::factory()->create())
            ->create();

        $result = $this->builder->build($server);

        $this->assertContains('{unknown_thing}', $result['startup']['args']);
    }

    public function test_rcon_password_never_appears_in_args(): void
    {
        $game = Game::factory()->create([
            'startup' => ['exec' => 'sh', 'args' => ['run.sh', '--rcon', '{rcon_password}']],
        ]);

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, Node::factory()->create(), Tariff::factory()->create())
            ->create();

        $result = $this->builder->build($server);

        // В команду запуска попадает только маска, сам пароль уходит в env
        $this->assertContains('********', $result['startup']['args']);
        $this->assertNotSame('********', $result['env']['RCON_PASSWORD'], 'в env лежит шифротекст');
    }

    public function test_rcon_password_is_generated_encrypted_and_reusable(): void
    {
        $server = $this->makeServer();

        $first = $this->builder->build($server);
        $this->assertArrayHasKey('RCON_PASSWORD', $first['env']);
        $this->assertNotNull($first['env']['RCON_PASSWORD']);

        // Сервер сохранил секрет — при повторной сборке он не меняется
        $server->forceFill(['env' => $first['env']])->save();
        $second = $this->builder->build($server);

        $this->assertSame($first['env']['RCON_PASSWORD'], $second['env']['RCON_PASSWORD']);
    }

    public function test_rcon_password_round_trips_through_model_accessor(): void
    {
        $server = $this->makeServer();
        $built = $this->builder->build($server);

        $server->forceFill(['env' => $built['env']])->save();

        $password = $server->fresh()->rconPassword();

        $this->assertNotNull($password);
        $this->assertSame(24, mb_strlen($password));
    }

    public function test_env_contains_panel_context(): void
    {
        $server = $this->makeServer(['name' => 'Мой мир']);
        $result = $this->builder->build($server);

        $this->assertSame('Мой мир', $result['env']['SERVER_NAME']);
        $this->assertSame($server->uuid, $result['env']['SERVER_UUID']);
        $this->assertSame((string) $server->id, $result['env']['GAMEDOCK_SERVER_ID']);
        $this->assertArrayHasKey('TZ', $result['env']);
        $this->assertArrayHasKey('GAMEDOCK_PANEL', $result['env']);
    }

    public function test_server_ip_falls_back_to_zero_when_node_has_no_address(): void
    {
        $node = Node::factory()->create(['flagship' => null, 'host' => null]);
        $game = Game::factory()->create([
            'startup' => ['exec' => 'sh', 'args' => ['--ip', '{server_ip}']],
        ]);

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, $node, Tariff::factory()->create())
            ->create();

        $this->assertContains('0.0.0.0', $this->builder->build($server)['startup']['args']);
    }

    public function test_map_and_worldsize_come_from_config_values(): void
    {
        $server = $this->makeServer([], [
            'startup' => ['exec' => 'sh', 'args' => ['{map}', '{worldsize}']],
        ]);

        $server->forceFill(['config_values' => ['level-name' => 'creative', 'server.worldsize' => 8000]])->save();

        $args = $this->builder->build($server)['startup']['args'];

        $this->assertContains('creative', $args);
        $this->assertContains('8000', $args);
    }

    public function test_install_command_for_steamcmd_game(): void
    {
        $game = Game::factory()->create([
            'uses_steamcmd' => true,
            'steam_appid' => 730,
            'installer' => ['type' => 'steamcmd', 'app_id' => 730],
        ]);

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, Node::factory()->create(), Tariff::factory()->create())
            ->create();

        $this->assertStringContainsString('steamcmd', (string) $this->builder->installCommand($game, $server));
        $this->assertStringContainsString('app_update 730', (string) $this->builder->installCommand($game, $server));
    }

    public function test_install_command_is_null_for_games_without_installer(): void
    {
        $server = $this->makeServer();

        $this->assertNull($this->builder->installCommand($server->game, $server));
    }

    public function test_display_command_joins_exec_and_args(): void
    {
        $server = $this->makeServer();

        $this->assertSame(
            'java -Xms2048M -Xmx2048M jar server.jar --port 25000',
            $this->builder->displayCommand($server),
        );
    }

    public function test_default_env_from_game_is_merged(): void
    {
        $game = Game::factory()->create([
            'startup' => ['exec' => 'sh', 'args' => ['run.sh'], 'env' => ['A' => '1']],
            'default_env' => ['B' => '2', 'C' => '{server_name}'],
        ]);

        $server = Server::factory()
            ->ownedBy(User::factory()->create(), $game, Node::factory()->create(), Tariff::factory()->create())
            ->create(['name' => 'X']);

        $env = $this->builder->build($server)['env'];

        $this->assertSame('1', $env['A']);
        $this->assertSame('2', $env['B']);
        $this->assertSame('X', $env['C']);
    }

    public function test_mask_secret_hides_value(): void
    {
        $masked = $this->builder->maskSecret('supersecretpassword123');

        $this->assertStringNotContainsString('supersecret', $masked);
        $this->assertStringContainsString('…', $masked);
        $this->assertSame('', $this->builder->maskSecret(null));
    }
}
