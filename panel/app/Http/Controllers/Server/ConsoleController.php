<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FileService;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Веб-консоль игрового сервера.
 *
 * Поток вывода идёт через SSE: агент → Redis → SSE → браузер.
 * Отправка команд — обычный POST.
 */
class ConsoleController extends Controller
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly ConsoleBroadcaster $console,
        private readonly FileService $files,
        private readonly ServerEventLogger $events,
    ) {}

    /**
     * Инвокаемый контроллер: маршрут задан как
     * Route::get('/console', ConsoleController::class) внутри префикса
     * servers/{server} — без метода. Без __invoke Laravel падает на
     * загрузке маршрутов.
     */
    public function __invoke(Request $request, Server $server): View
    {
        return $this->show($request, $server);
    }

    public function show(Request $request, Server $server): View
    {
        $this->authorize('console', $server);

        return view('panel.servers.console', [
            'server' => $server,
            'initialLines' => $this->console->tail($server->id, 300),
            'stats' => $this->console->getStats($server->id),
            'canControl' => $request->user()->can('power', $server),
            'reconnect' => $server->node_id !== null,
        ]);
    }

    /**
     * SSE-поток. Держит соединение, пока браузер открыт.
     */
    public function stream(Request $request, Server $server): StreamedResponse
    {
        $this->authorize('console', $server);

        $seconds = min((int) $request->query('timeout', 300), 3600);
        $buffer = (int) $request->query('buffer', 200);

        $node = $server->node;

        // Просим агента начать стримить в панель
        if ($node) {
            $this->agent->subscribeConsole($node, $server, $buffer);
        }

        $response = new StreamedResponse(function () use ($server, $seconds) {
            @set_time_limit($seconds + 30);

            $startedAt = time();
            $lastEventId = null;

            echo "retry: 3000\n\n";

            while (time() - $startedAt < $seconds) {
                if (connection_aborted()) {
                    break;
                }

                $lines = $this->console->drain($server->id);

                foreach ($lines as $line) {
                    $id = $line['ts'] ?? null;
                    if ($id !== null) {
                        $id = (int) $id.'-'.substr(md5(serialize($line)), 0, 6);
                        if ($id === $lastEventId) {
                            continue;
                        }
                        $lastEventId = $id;
                        echo 'id: '.$id."\n";
                    }

                    echo 'event: line'."\n";
                    echo 'data: '.json_encode([
                        'stream' => $line['stream'] ?? 'stdout',
                        'text' => $line['text'] ?? '',
                        'type' => $line['type'] ?? 'stdout',
                        'ts' => $line['ts'] ?? time(),
                    ], JSON_UNESCAPED_UNICODE)."\n\n";
                }

                $stats = $this->console->getStats($server->id);
                if ($stats !== []) {
                    echo 'event: stats'."\n";
                    echo 'data: '.json_encode($stats, JSON_UNESCAPED_UNICODE)."\n\n";
                }

                // Комментарий-heartbeat, чтобы прокси не рвал соединение
                echo ': ping '.(int) (microtime(true) * 1000)."\n\n";

                flush();

                usleep(400_000);
            }

            echo "event: close\n";
            echo 'data: '.json_encode(['reason' => 'timeout'])."\n\n";
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx не буферизует
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    public function send(Request $request, Server $server): \Illuminate\Http\JsonResponse
    {
        $this->authorize('console', $server);

        $data = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
        ]);

        $command = trim($data['command']);

        if ($command === '') {
            return response()->json(['message' => 'Пустая команда'], 422);
        }

        // Секретные коды обрабатываем на стороне панели, а не агента
        if (app(\App\Services\SecretCodes\SecretCodeResolver::class)->extractCode($command) !== null) {
            return response()->json(['message' => __('secret_codes.errors.use_in_chat')], 422);
        }

        $node = $server->node;

        if (! $node) {
            return response()->json(['message' => __('nodes.errors.offline'), 'disconnected' => true], 503);
        }

        $this->console->push($server->id, [
            'stream' => 'command',
            'text' => '> '.$command,
            'type' => 'command',
        ]);

        $result = $this->agent->writeConsole($node, $server, $command);

        if (! $result['ok'] && $result['mode'] === 'none') {
            return response()->json(['message' => $result['error'] ?? __('servers.errors.agent_error')], 503);
        }

        return response()->json([
            'ok' => true,
            'queued' => $result['mode'] === 'queue',
        ]);
    }

    // ── Логи ────────────────────────────────────────────────────────────

    public function logs(Request $request, Server $server): View
    {
        $this->authorize('console', $server);

        $lines = min((int) $request->query('lines', 300), 2000);
        $file = $request->query('file');

        $data = $this->files->logs($server, $lines, is_string($file) ? $file : null);

        return view('panel.servers.logs', [
            'server' => $server,
            'lines' => $data['lines'] ?? [],
            'logFiles' => $data['files'] ?? [],
            'currentFile' => $file,
            'error' => $data['error'],
        ]);
    }

    public function search(Request $request, Server $server): View
    {
        $this->authorize('console', $server);

        $query = (string) $request->query('q', '');
        $data = $query === ''
            ? ['ok' => true, 'lines' => [], 'total' => 0, 'error' => null]
            : $this->files->searchLogs($server, $query, min((int) $request->query('max', 200), 500));

        return view('panel.servers.logs-search', [
            'server' => $server,
            'query' => $query,
            'lines' => $data['lines'] ?? [],
            'total' => $data['total'] ?? 0,
            'error' => $data['error'],
        ]);
    }

    public function download(Request $request, Server $server): Response
    {
        $this->authorize('console', $server);

        $lines = min((int) $request->query('lines', 5000), 50000);
        $data = $this->files->logs($server, $lines, $request->query('file'));

        $content = implode("\n", array_map(
            static fn ($line) => is_array($line) ? (string) ($line['text'] ?? '') : (string) $line,
            $data['lines'] ?? [],
        ));

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$server->name.'-logs-'.now()->format('Ymd-His').'.txt"',
        ]);
    }
}
