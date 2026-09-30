<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Server;
use App\Services\Games\ConfigFileService;
use Illuminate\Support\Facades\Log;

/**
 * Файловые операции на игровом сервере.
 *
 * Здесь — вся проверка безопасности путей: пользователь не должен выйти
 * за пределы каталога сервера (../../../etc/passwd).
 */
class FileService
{
    /** Файлы, которые нельзя редактировать из панели даже владельцу. */
    private const PROTECTED = [
        '/.ssh/', '/.env', '/sudoers', '/shadow', '/passwd',
    ];

    /** Файлы, которые нельзя удалять. */
    private const IMMUTABLE = [
        'server.properties', 'server.cfg', 'config.json',
        'altv.config.js', 'pocketmine.yml', 'Commands.dat', 'GameUserSettings.ini',
    ];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly ConfigFileService $configs,
    ) {}

    // ── Чтение ──────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, entries: array, path: string, error: ?string}
     */
    public function list(Server $server, string $path = '.'): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'entries' => [], 'path' => $path, 'error' => __('files.errors.bad_path')];
        }

        $result = $this->dispatch($server, AgentClient::TYPE_FILES_LIST, ['path' => $safe]);

        if (! $result['ok']) {
            return ['ok' => false, 'entries' => [], 'path' => $safe, 'error' => $result['error']];
        }

        $entries = $result['result']['entries'] ?? [];

        // Сортировка: папки первыми, потом файлы, по имени
        usort($entries, static function (array $a, array $b) {
            if (($a['type'] ?? '') === ($b['type'] ?? '')) {
                return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            }

            return ($a['type'] ?? '') === 'dir' ? -1 : 1;
        });

        return ['ok' => true, 'entries' => $entries, 'path' => $safe, 'error' => null];
    }

    /**
     * @return array{ok: bool, content: string, size: int, binary: bool, error: ?string}
     */
    public function read(Server $server, string $path, int $maxBytes = 524288): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'binary' => false, 'error' => __('files.errors.bad_path')];
        }

        $result = $this->dispatch($server, AgentClient::TYPE_FILES_READ, [
            'path' => $safe,
            'max_bytes' => $maxBytes,
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'binary' => false, 'error' => $result['error']];
        }

        $data = $result['result'] ?? [];
        $encoded = $data['content'] ?? '';

        $content = base64_decode($encoded, true) ?: '';
        $binary = (bool) ($data['binary'] ?? false);

        if ($binary) {
            $content = '';
        }

        return [
            'ok' => true,
            'content' => $content,
            'size' => (int) ($data['size'] ?? strlen($content)),
            'binary' => $binary,
            'error' => null,
        ];
    }

    // ── Запись ──────────────────────────────────────────────────────────

    public function write(Server $server, string $path, string $content): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'error' => __('files.errors.bad_path')];
        }

        if ($this->isProtected($safe)) {
            return ['ok' => false, 'error' => __('files.errors.protected')];
        }

        if (strlen($content) > 2 * 1024 * 1024) {
            return ['ok' => false, 'error' => __('files.errors.too_big', ['max' => '2 МБ'])];
        }

        return $this->dispatch($server, AgentClient::TYPE_FILES_WRITE, [
            'path' => $safe,
            'content' => base64_encode($content),
            'encoding' => 'utf-8',
        ]);
    }

    public function createDirectory(Server $server, string $path): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'error' => __('files.errors.bad_path')];
        }

        return $this->dispatch($server, AgentClient::TYPE_FILES_MKDIR, ['path' => $safe]);
    }

    public function rename(Server $server, string $from, string $to): array
    {
        $safeFrom = $this->safePath($from);
        $safeTo = $this->safePath($to);

        if ($safeFrom === false || $safeTo === false) {
            return ['ok' => false, 'error' => __('files.errors.bad_path')];
        }

        return $this->dispatch($server, AgentClient::TYPE_FILES_RENAME, [
            'from' => $safeFrom,
            'to' => $safeTo,
        ]);
    }

    public function delete(Server $server, string $path, bool $recursive = false): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'error' => __('files.errors.bad_path')];
        }

        $name = basename($safe);

        if ($recursive && in_array($name, ['.', '..', 'plugins', 'mods', 'world', 'resources'], true)) {
            return ['ok' => false, 'error' => __('files.errors.protected_dir', ['name' => $name])];
        }

        return $this->dispatch($server, AgentClient::TYPE_FILES_DELETE, [
            'path' => $safe,
            'recursive' => $recursive,
        ]);
    }

    public function search(Server $server, string $query, string $path = '.'): array
    {
        $safe = $this->safePath($path);

        if ($safe === false) {
            return ['ok' => false, 'results' => [], 'error' => __('files.errors.bad_path')];
        }

        $result = $this->dispatch($server, AgentClient::TYPE_FILES_SEARCH, [
            'query' => $query,
            'path' => $safe,
        ]);

        return [
            'ok' => $result['ok'],
            'results' => $result['result']['matches'] ?? [],
            'error' => $result['error'],
        ];
    }

    // ── Конфиги ─────────────────────────────────────────────────────────

    /**
     * Прочитать конфиг игры и разложить по схеме.
     *
     * @return array{ok: bool, values: array, raw: string, format: string, error: ?string}
     */
    public function readConfig(Server $server, array $definition): array
    {
        $path = $definition['path'];

        $file = $this->read($server, $path, 1024 * 1024);

        if (! $file['ok']) {
            return ['ok' => false, 'values' => [], 'raw' => '', 'format' => $definition['format'] ?? 'properties', 'error' => $file['error']];
        }

        $format = $definition['format'] ?? 'properties';
        $values = $this->configs->extractFields($file['content'], $definition['fields'] ?? [], $format);

        return [
            'ok' => true,
            'values' => $values,
            'raw' => $file['content'],
            'format' => $format,
            'error' => null,
        ];
    }

    /**
     * Применить значения из панели в конфиг игры.
     *
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, error: ?string}
     */
    public function updateConfig(Server $server, string $path, array $values, array $definition): array
    {
        $file = $this->read($server, $path, 1024 * 1024);

        if (! $file['ok']) {
            return ['ok' => false, 'error' => $file['error'] ?? __('files.errors.read_failed')];
        }

        $format = $definition['format'] ?? 'properties';
        $updated = $this->configs->write($file['content'], $values, $format);

        return $this->write($server, $path, $updated);
    }

    // ── Логи ────────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, lines: array, files: array, error: ?string}
     */
    public function logs(Server $server, int $lines = 200, ?string $file = null): array
    {
        $result = $this->dispatch($server, AgentClient::TYPE_LOGS_READ, [
            'lines' => $lines,
            'file' => $file,
        ]);

        return [
            'ok' => $result['ok'],
            'lines' => $result['result']['lines'] ?? [],
            'files' => $result['result']['files'] ?? [],
            'error' => $result['error'],
        ];
    }

    public function searchLogs(Server $server, string $query, int $max = 200): array
    {
        $result = $this->dispatch($server, AgentClient::TYPE_LOGS_READ, [
            'query' => $query,
            'max' => $max,
        ]);

        return [
            'ok' => $result['ok'],
            'lines' => $result['result']['lines'] ?? [],
            'total' => (int) ($result['result']['total'] ?? 0),
            'error' => $result['error'],
        ];
    }

    // ── Безопасность путей ──────────────────────────────────────────────

    /**
     * Нормализует путь относительно каталога сервера.
     * Возвращает false, если путь пытается выйти за пределы каталога.
     */
    public function safePath(string $path): string|false
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || $path === '.') {
            return '.';
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return false; // выход за пределы каталога
            }

            if (str_contains($segment, "\0")) {
                return false;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '.' : implode('/', $segments);
    }

    public function isProtected(string $path): bool
    {
        foreach (self::PROTECTED as $needle) {
            if (str_contains('/'.strtolower($path), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public function isImmutable(string $path): bool
    {
        return in_array(basename($path), self::IMMUTABLE, true);
    }

    /** Человеческий путь с каталогом. */
    public function breadcrumbs(string $path): array
    {
        $parts = array_filter(explode('/', trim($path, '/')));

        $crumbs = [['name' => __('files.root'), 'path' => '.']];
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current.'/'.$part;
            $crumbs[] = ['name' => $part, 'path' => $current];
        }

        return $crumbs;
    }

    // ── Отправка агенту ─────────────────────────────────────────────────

    /**
     * Отправляет команду агенту. Если ноды нет в памяти — положит в очередь
     * и вернёт «ожидание»: интерфейс покажет, что команда в очереди.
     *
     * @return array{ok: bool, mode: string, result: array, error: ?string}
     */
    private function dispatch(Server $server, string $type, array $payload = []): array
    {
        $node = $server->node;

        if (! $node) {
            return ['ok' => false, 'mode' => 'none', 'result' => [], 'error' => __('nodes.errors.offline')];
        }

        $payload['server_id'] = $server->id;
        $payload['uuid'] = $server->uuid;

        $response = null;
        $sent = $this->agent->send(
            $node->id,
            $type,
            $payload,
            function (array $message) use (&$response) {
                $response = $message;
            },
        );

        if (! $sent['ok']) {
            return ['ok' => false, 'mode' => $sent['mode'], 'result' => [], 'error' => $sent['error']];
        }

        // Сокет доступен только в процессе WSS-сервера; в веб-запросе
        // ответа не будет — отдаём пустой результат без ошибки.
        if ($response === null) {
            return ['ok' => $sent['mode'] === 'queue', 'mode' => $sent['mode'], 'result' => [], 'error' => null];
        }

        $ok = (bool) ($response['ok'] ?? false);

        return [
            'ok' => $ok,
            'mode' => $sent['mode'],
            'result' => (array) ($response['result'] ?? $response['data'] ?? []),
            'error' => $ok ? null : ($response['error'] ?? __('servers.errors.agent_error')),
        ];
    }

    /** Синхронное ожидание ответа — только для CLI/очередей. */
    public function dispatchBlocking(Server $server, string $type, array $payload = [], int $timeout = 15): array
    {
        $node = $server->node;

        if (! $node) {
            return ['ok' => false, 'result' => [], 'error' => __('nodes.errors.offline')];
        }

        $payload['server_id'] = $server->id;

        $response = null;

        $this->agent->send($node->id, $type, $payload, function (array $message) use (&$response) {
            $response = $message;
        });

        if ($response === null) {
            return ['ok' => false, 'result' => [], 'error' => 'Нет соединения с нодой — команда поставлена в очередь'];
        }

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'result' => (array) ($response['result'] ?? []),
            'error' => $response['error'] ?? null,
        ];
    }
}
