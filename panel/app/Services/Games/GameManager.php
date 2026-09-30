<?php

declare(strict_types=1);

namespace App\Services\Games;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\Agent\AgentClient;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/**
 * Управление каталогом игр и шаблонами (плагинами/модами).
 * Всё редактируется из админки, без правки кода.
 */
class GameManager
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly ServerEventLogger $events,
    ) {}

    // ── Игры ────────────────────────────────────────────────────────────

    public function create(array $data): Game
    {
        $validated = $this->validateGame($data);

        $validated['slug'] = $this->uniqueSlug($validated['slug'] ?? $validated['name']);
        $validated['is_custom'] = true;

        return DB::transaction(fn () => Game::create($validated));
    }

    public function update(Game $game, array $data): Game
    {
        $validated = $this->validateGame($data, $game);

        if (isset($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['slug'], $game->id);
        }

        $game->fill($validated)->save();

        return $game->refresh();
    }

    /**
     * Валидация структуры startup/installer — самая частая причина «сервер не стартует».
     *
     * @return array<string, mixed>
     */
    private function validateGame(array $data, ?Game $game = null): array
    {
        $startup = $data['startup'] ?? $game?->startup ?? [];
        $installer = $data['installer'] ?? $game?->installer ?? ['type' => 'none'];

        $errors = [];

        if (blank($startup['exec'] ?? null)) {
            $errors['startup.exec'] = 'Укажите исполняемый файл (например java, ./server, node)';
        }

        if (($startup['exec'] ?? '') !== '' && str_contains((string) $startup['exec'], '..')) {
            $errors['startup.exec'] = 'Путь не должен содержать «..»';
        }

        $args = $startup['args'] ?? [];
        if (! is_array($args)) {
            $errors['startup.args'] = 'Аргументы должны быть списком';
        }

        $installerType = $installer['type'] ?? 'none';

        if (in_array($installerType, ['steamcmd'], true) && ! ($installer['app_id'] ?? $data['steam_appid'] ?? null)) {
            $errors['installer.app_id'] = 'Для SteamCMD нужен AppID';
        }

        if ($installerType === 'script' && blank($installer['script'] ?? null)) {
            $errors['installer.script'] = 'Укажите путь к скрипту установки';
        }

        if (! empty($data['ports'])) {
            $ports = (array) $data['ports'];
            if (count($ports) !== count(array_unique($ports))) {
                $errors['ports'] = 'Порты должны быть разными';
            }
        }

        if ($errors) {
            throw new \Illuminate\Validation\ValidationException(
                Validator::make([], []),
                $errors,
            );
        }

        // Числа приводим к int, чтобы не ловить строки из JSON
        foreach (['min_slots', 'max_slots', 'default_slots', 'slot_step',
            'default_memory_mb', 'min_memory_mb', 'default_cpu_percent', 'default_disk_mb',
            'steam_appid', 'sort'] as $intField) {
            if (array_key_exists($intField, $data)) {
                $data[$intField] = (int) $data[$intField];
            }
        }

        if (array_key_exists('price_per_slot_month', $data)) {
            $data['price_per_slot_month'] = round((float) $data['price_per_slot_month'], 2);
        }

        if (array_key_exists('startup', $data)) {
            $data['startup'] = $this->normalizeStartup($data['startup']);
        }

        return $data;
    }

    private function normalizeStartup(array $startup): array
    {
        $startup['exec'] = trim((string) ($startup['exec'] ?? ''));
        $startup['args'] = array_values(array_map(
            static fn ($a) => (string) $a,
            (array) ($startup['args'] ?? []),
        ));
        $startup['cwd'] = (string) ($startup['cwd'] ?? '.');
        $startup['user'] = (string) ($startup['user'] ?? 'gamedock');
        $startup['stop_signal'] = (string) ($startup['stop_signal'] ?? 'SIGTERM');
        $startup['stop_timeout'] = (int) ($startup['stop_timeout'] ?? 30);

        if (! is_array($startup['env'] ?? null)) {
            $startup['env'] = [];
        }

        return $startup;
    }

    public function delete(Game $game): bool
    {
        if ($game->servers()->exists()) {
            throw new \RuntimeException(__('admin.games.errors.has_servers'));
        }

        return $game->delete();
    }

    public function duplicate(Game $game, string $name): Game
    {
        $copy = $game->replicate();
        $copy->name = $name;
        $copy->slug = $this->uniqueSlug($name);
        $copy->is_custom = true;
        $copy->is_public = false;
        $copy->save();

        // Конфиг-файлы: копия без read_only-полей
        $copy->forceFill([
            'config_files' => array_map(static function (array $f) {
                if (isset($f['fields'])) {
                    $f['fields'] = array_values(array_filter(
                        $f['fields'],
                        static fn (array $field) => empty($field['read_only']),
                    ));
                }

                return $f;
            }, $game->config_files ?? []),
        ])->save();

        return $copy;
    }

    private function uniqueSlug(string $source, ?int $exceptId = null): string
    {
        $base = Str::slug($source) ?: 'game';
        $slug = $base;
        $i = 1;

        while (Game::where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    // ── Шаблоны (плагины/моды) ──────────────────────────────────────────

    public function createTemplate(Game $game, array $data): GameTemplate
    {
        $data['game_id'] = $game->id;

        return GameTemplate::create($data);
    }

    public function updateTemplate(GameTemplate $template, array $data): GameTemplate
    {
        $template->fill($data)->save();

        return $template->refresh();
    }

    public function deleteTemplate(GameTemplate $template): bool
    {
        return $template->delete();
    }

    /**
     * Установить шаблон на сервер.
     *
     * @return array{ok: bool, install: \App\Models\GameTemplateInstall|null, error: ?string}
     */
    public function installOnServer(GameTemplate $template, \App\Models\Server $server, ?\App\Models\User $user = null): array
    {
        $node = $server->node;

        if (! $node) {
            return ['ok' => false, 'install' => null, 'error' => __('nodes.errors.offline')];
        }

        // Проверяем, не установлен ли уже
        $existing = \App\Models\GameTemplateInstall::where('game_template_id', $template->id)
            ->where('server_id', $server->id)
            ->where('status', 'installed')
            ->first();

        if ($existing) {
            return ['ok' => false, 'install' => $existing, 'error' => __('games.errors.already_installed')];
        }

        $install = \App\Models\GameTemplateInstall::create([
            'game_template_id' => $template->id,
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'status' => 'queued',
        ]);

        $version = $template->game_version ?: $server->build_version;

        $result = $this->agent->installTemplate($node, $server, [
            'template_id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'type' => $template->type,
            'version' => $template->version,
            'source_type' => $template->source_type,
            'source_url' => $template->resolveUrl($version),
            'builtin_path' => $template->builtin_path,
            's3_key' => $template->s3_key,
            'target_path' => $template->targetPath(),
            'env_replace' => $template->env_replace,
            'post_commands' => $template->post_commands,
            'checksum_urls' => $template->checksum_urls,
            'game_version' => $version,
        ]);

        if (! $result['ok']) {
            $install->forceFill(['status' => 'failed', 'error' => $result['error']])->save();

            return ['ok' => false, 'install' => $install, 'error' => $result['error']];
        }

        $install->forceFill(['status' => 'downloading'])->save();
        $template->increment('installs_count');

        $this->events->log(
            $server,
            'file',
            __('games.events.template_installed', ['name' => $template->name]),
            'info',
            ['template' => $template->slug],
            $user,
        );

        return ['ok' => true, 'install' => $install, 'error' => null];
    }

    public function removeFromServer(GameTemplate $template, \App\Models\Server $server, ?\App\Models\User $user = null): array
    {
        $node = $server->node;

        if (! $node) {
            return ['ok' => false, 'error' => __('nodes.errors.offline')];
        }

        $result = $this->agent->removeTemplate($node, $server, [
            'template_id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'target_path' => $template->targetPath(),
        ]);

        \App\Models\GameTemplateInstall::where('game_template_id', $template->id)
            ->where('server_id', $server->id)
            ->update(['status' => 'removed']);

        if ($result['ok']) {
            $this->events->log(
                $server,
                'file',
                __('games.events.template_removed', ['name' => $template->name]),
                'info',
                ['template' => $template->slug],
                $user,
            );
        }

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    // ── Обновления ──────────────────────────────────────────────────────

    /**
     * Проверка новой версии панели. Без URL — просто пишем в лог.
     */
    public function checkForUpdates(): ?string
    {
        $url = (string) setting('hosting.updates.url', '');

        if (blank($url)) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->get($url);
            $data = $response->json();

            $latest = $data['version'] ?? $data['tag_name'] ?? null;

            if (! $latest) {
                return null;
            }

            $current = config('app.version', '1.0.0');

            if (version_compare(ltrim((string) $latest, 'v'), $current, '>')) {
                \Illuminate\Support\Facades\Log::info("Доступно обновление панели: {$latest} (текущая {$current})");

                $webhook = (string) setting('hosting.updates.webhook', '');

                if ($webhook) {
                    \Illuminate\Support\Facades\Http::timeout(10)->post($webhook, [
                        'version' => $latest,
                        'channel' => setting('hosting.updates.channel', 'stable'),
                        'url' => $url,
                    ]);
                }

                return (string) $latest;
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('Проверка обновлений не удалась: '.$e->getMessage());

            return null;
        }
    }
}
