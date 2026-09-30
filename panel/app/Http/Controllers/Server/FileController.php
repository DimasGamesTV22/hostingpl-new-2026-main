<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Agent\FileService;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class FileController extends Controller
{
    public function __construct(
        private readonly FileService $files,
        private readonly ServerEventLogger $events,
    ) {}

    public function index(Request $request, Server $server): View
    {
        $this->authorize('files', $server);

        $path = (string) $request->query('path', '.');
        $listing = $this->files->list($server, $path);

        return view('panel.servers.files', [
            'server' => $server,
            'path' => $listing['path'] ?? $path,
            'entries' => $listing['entries'] ?? [],
            'breadcrumbs' => $this->files->breadcrumbs($listing['path'] ?? $path),
            'error' => $listing['error'],
            'canWrite' => $request->user()->can('writeFiles', $server),
            'usage' => [
                'used' => (int) $server->disk_used_mb,
                'total' => (int) $server->disk_mb,
            ],
        ]);
    }

    public function list(Request $request, Server $server): JsonResponse
    {
        $this->authorize('files', $server);

        $listing = $this->files->list($server, (string) $request->query('path', '.'));

        return response()->json($listing);
    }

    public function read(Request $request, Server $server): View|JsonResponse
    {
        $this->authorize('files', $server);

        $path = (string) $request->query('path', '');

        $file = $this->files->read($server, $path, 524288);

        if (! $file['ok']) {
            if ($request->expectsJson()) {
                return response()->json($file, 422);
            }

            return back()->with('error', $file['error']);
        }

        if ($request->expectsJson()) {
            return response()->json($file);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return view('panel.servers.file-edit', [
            'server' => $server,
            'path' => $path,
            'content' => $file['content'],
            'size' => $file['size'],
            'binary' => $file['binary'],
            'language' => $this->languageFor($extension),
            'canWrite' => $request->user()->can('writeFiles', $server) && ! $file['binary'],
        ]);
    }

    public function save(Request $request, Server $server): JsonResponse
    {
        $this->authorize('writeFiles', $server);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'content' => ['nullable', 'string', 'max:2097152'],
        ]);

        $result = $this->files->write($server, $data['path'], (string) ($data['content'] ?? ''));

        if (! $result['ok'] && $result['mode'] !== 'queue') {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $this->events->files($server, 'file_saved', $data['path'], $request->user());

        return response()->json([
            'ok' => true,
            'queued' => ($result['mode'] ?? '') === 'queue',
            'message' => __('files.messages.saved'),
        ]);
    }

    public function upload(Request $request, Server $server): JsonResponse
    {
        $this->authorize('writeFiles', $server);

        $request->validate([
            'file' => ['required', 'file', 'max:5242880'],
            'path' => ['nullable', 'string', 'max:512'],
        ]);

        $upload = $request->file('file');
        $target = (string) ($request->input('path') ?: '.');
        $safeTarget = $this->files->safePath($target);

        if ($safeTarget === false) {
            return response()->json(['ok' => false, 'message' => __('files.errors.bad_path')], 422);
        }

        $name = $upload->getClientOriginalName();
        $safeName = preg_replace('/[^A-Za-z0-9._\- ]+/u', '', $name) ?: 'file';
        $destination = ($safeTarget === '.' ? '' : $safeTarget.'/').$safeName;

        $content = file_get_contents($upload->getRealPath());
        $result = $this->files->write($server, $destination, (string) $content);

        if (! $result['ok'] && ($result['mode'] ?? '') !== 'queue') {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $this->events->files($server, 'file_uploaded', $destination, $request->user());

        return response()->json(['ok' => true, 'path' => $destination]);
    }

    public function mkdir(Request $request, Server $server): JsonResponse
    {
        $this->authorize('writeFiles', $server);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
        ]);

        $result = $this->files->createDirectory($server, $data['path']);

        if (! $result['ok'] && ($result['mode'] ?? '') !== 'queue') {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $this->events->files($server, 'dir_created', $data['path'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function rename(Request $request, Server $server): JsonResponse
    {
        $this->authorize('writeFiles', $server);

        $data = $request->validate([
            'from' => ['required', 'string', 'max:512'],
            'to' => ['required', 'string', 'max:512'],
        ]);

        $result = $this->files->rename($server, $data['from'], $data['to']);

        if (! $result['ok'] && ($result['mode'] ?? '') !== 'queue') {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $this->events->files($server, 'file_renamed', $data['from'].' → '.$data['to'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $this->authorize('writeFiles', $server);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'recursive' => ['nullable', 'boolean'],
        ]);

        $result = $this->files->delete($server, $data['path'], $request->boolean('recursive'));

        if (! $result['ok'] && ($result['mode'] ?? '') !== 'queue') {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $this->events->files($server, 'file_deleted', $data['path'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, Server $server)
    {
        $this->authorize('files', $server);

        $path = (string) $request->query('path', '');
        $file = $this->files->read($server, $path, 10 * 1024 * 1024);

        if (! $file['ok']) {
            abort(404, $file['error'] ?? 'Файл не найден');
        }

        return response($file['content'], 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename($path).'"',
        ]);
    }

    public function search(Request $request, Server $server): View
    {
        $this->authorize('files', $server);

        $query = (string) $request->query('q', '');
        $result = $query === ''
            ? ['ok' => true, 'results' => [], 'error' => null]
            : $this->files->search($server, $query, (string) $request->query('path', '.'));

        return view('panel.servers.files-search', [
            'server' => $server,
            'query' => $query,
            'results' => $result['results'] ?? [],
            'error' => $result['error'],
        ]);
    }

    private function languageFor(string $extension): string
    {
        return match ($extension) {
            'json' => 'json',
            'yml', 'yaml' => 'yaml',
            'properties', 'cfg', 'ini', 'conf' => 'ini',
            'xml' => 'xml',
            'js' => 'javascript',
            'sh' => 'bash',
            'lua' => 'lua',
            'sql' => 'sql',
            'java', 'cs' => 'java',
            default => 'text',
        };
    }
}
