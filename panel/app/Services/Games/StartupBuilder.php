<?php

declare(strict_types=1);

namespace App\Services\Games;

use App\Models\Game;
use App\Models\Server;
use App\Support\Crypto;

/**
 * Сборка команды запуска и переменных окружения.
 *
 * Игра отдаёт шаблон startup с плейсхолдерами:
 *   {ram_mb} {cpu_percent} {slots} {game_port} {query_port} {rcon_port}
 *   {server_name} {rcon_password} {map} {game_mode} {worldsize} {server_ip}
 *   {steam_query_key} {build} {server_id}
 */
class StartupBuilder
{
    /** Плейсхолдеры, которые вычисляются на лету. */
    public const PLACEHOLDERS = [
        'ram_mb', 'ram_gb', 'cpu_percent', 'cpu_cores', 'slots', 'players',
        'game_port', 'query_port', 'rcon_port', 'server_name', 'server_id',
        'server_ip', 'rcon_password', 'worldsize', 'steam_query_key',
        'map', 'game_mode', 'build', 'disk_mb', 'network_mbps',
    ];

    /**
     * @return array{
     *   startup: array<string, mixed>,
     *   env: array<string, string>,
     *   install_command: ?string
     * }
     */
    public function build(Server $server): array
    {
        $game = $server->game;
        $build = $server->build_version;

        $template = $game->startup ?? [];

        // Сборка может переопределить startup (свои java-флаги, свой порт)
        $buildDef = $build ? $game->build($build) : null;

        if (is_array($buildDef) && isset($buildDef['startup'])) {
            $template = array_replace_recursive($template, (array) $buildDef['startup']);
        }

        $rconPassword = $server->rconPassword() ?: $this->rememberRconPassword($server);

        $values = [
            'ram_mb' => (string) $server->memory_mb,
            'ram_gb' => (string) round($server->memory_mb / 1024, 2),
            'cpu_percent' => (string) $server->cpu_percent,
            'cpu_cores' => (string) max(0.1, round($server->cpu_percent / 100, 2)),
            'slots' => (string) $server->slots,
            'players' => (string) $server->players_online,
            'game_port' => (string) ($server->game_port ?? 0),
            'query_port' => (string) ($server->query_port ?? 0),
            'rcon_port' => (string) ($server->rcon_port ?? 0),
            'server_name' => $server->name,
            'server_id' => (string) $server->id,
            'server_ip' => $this->serverIp($server),
            'rcon_password' => $rconPassword,
            'worldsize' => (string) (data_get($server->config_values, 'server.worldsize', 4000)),
            'steam_query_key' => (string) (data_get($server->config_values, 'steam_query_key', '')),
            'map' => (string) (data_get($server->config_values, 'map') ?? data_get($server->config_values, 'level-name', 'world')),
            'game_mode' => (string) (data_get($server->config_values, 'gamemode0', 'grandlarc 1')),
            'build' => (string) $build,
            'disk_mb' => (string) $server->disk_mb,
            'network_mbps' => (string) $server->network_mbps,
        ];

        $startup = [
            'exec' => $this->interpolate((string) ($template['exec'] ?? ''), $values),
            'args' => array_map(
                fn ($arg) => $this->interpolate((string) $arg, $values),
                (array) ($template['args'] ?? []),
            ),
            'cwd' => $template['cwd'] ?? '.',
            'user' => $template['user'] ?? $game->working_user,
            'stop_signal' => $template['stop_signal'] ?? 'SIGTERM',
            'stop_timeout' => (int) ($template['stop_timeout'] ?? 30),
            'rcon' => $template['rcon'] ?? null,
            'query' => $template['query'] ?? null,
            'healthcheck' => $template['healthcheck'] ?? ['type' => 'process', 'interval' => 30],
        ];

        $env = [];
        foreach ((array) ($template['env'] ?? []) as $key => $value) {
            $env[(string) $key] = $this->interpolate((string) $value, $values);
        }

        foreach ((array) $game->default_env as $key => $value) {
            $env[(string) $key] = $this->interpolate((string) $value, $values);
        }

        // Секреты храним зашифрованными — агент расшифровывает на своей стороне
        if ($rconPassword !== '') {
            $env['RCON_PASSWORD'] = Crypto::encrypt($rconPassword);
        }

        $env['SERVER_UUID'] = $server->uuid;
        $env['SERVER_NAME'] = $server->name;
        $env['GAMEDOCK_PANEL'] = (string) config('app.url');
        $env['GAMEDOCK_SERVER_ID'] = (string) $server->id;
        $env['TZ'] = (string) setting('hosting.locale.timezone', 'Europe/Moscow');

        return [
            'startup' => $startup,
            'env' => $env,
            'install_command' => $this->installCommand($game, $server),
        ];
    }

    public function installCommand(Game $game, Server $server): ?string
    {
        $installer = $game->installer ?? [];
        $type = $installer['type'] ?? 'none';

        return match ($type) {
            'steamcmd' => sprintf(
                'steamcmd +force_install_dir {dir} +login anonymous +app_update %d validate +quit',
                (int) ($installer['app_id'] ?? $game->steam_appid ?? 0),
            ),
            'script' => (string) ($installer['script'] ?? ''),
            'download' => sprintf('wget -O {dir}/download %s', $installer['source_url'] ?? ''),
            default => null,
        };
    }

    /** Команда, показываемая пользователю в «Информации о сервере». */
    public function displayCommand(Server $server): string
    {
        $startup = $server->startup ?? [];
        $parts = [$startup['exec'] ?? ''];

        foreach ((array) ($startup['args'] ?? []) as $arg) {
            $parts[] = $arg;
        }

        return trim(implode(' ', array_filter($parts, static fn ($p) => $p !== '')));
    }

    private function interpolate(string $template, array $values): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($values) {
            if (str_contains($m[1], 'rcon_password')) {
                return '********';
            }

            return (string) ($values[$m[1]] ?? $m[0]);
        }, $template) ?? $template;
    }

    private function serverIp(Server $server): string
    {
        $node = $server->node;

        return $node?->flagship ?: ($node?->host ?: '0.0.0.0');
    }

    /** Генерирует и сохраняет RCON-пароль при первом запуске. */
    private function rememberRconPassword(Server $server): string
    {
        $password = Crypto::randomPassword((int) $server->rcon_password_length);

        $env = $server->env ?? [];
        $env['RCON_PASSWORD'] = Crypto::encrypt($password);
        $server->env = $env;

        return $password;
    }

    /** Маска для показа RCON-пароля в интерфейсе. */
    public function maskSecret(?string $value): string
    {
        return Crypto::mask($value);
    }
}
