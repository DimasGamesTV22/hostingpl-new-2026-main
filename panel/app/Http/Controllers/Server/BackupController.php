<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Services\Backups\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request, Server $server): View
    {
        $this->authorize('backup', $server);

        $snapshots = $server->snapshots()
            ->orderByDesc('created_at')
            ->paginate(24);

        return view('panel.servers.backups', [
            'server' => $server,
            'snapshots' => $snapshots,
            'quota' => $this->backups->quotaFor($server),
            'quotaLeft' => $this->backups->quotaLeft($server),
            'canRestore' => $request->user()->can('restore', $server),
            'schedule' => setting('hosting.backups.default_schedule', 'daily'),
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('backup', $server);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $snapshot = $this->backups->create($server, $request->user(), [
            'name' => $data['name'] ?? null,
        ]);

        return back()->with('success', __('backups.messages.created', ['name' => $snapshot->name]));
    }

    public function restore(Request $request, Server $server, ServerSnapshot $snapshot): RedirectResponse
    {
        $this->authorize('restore', $server);

        abort_if($snapshot->server_id !== $server->id, 404);

        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        $ok = $this->backups->restore($server, $snapshot, $request->user());

        return $ok
            ? back()->with('warning', __('backups.messages.restoring', ['name' => $snapshot->name]))
            : back()->with('error', __('backups.errors.restore_failed', ['error' => '']));
    }

    public function download(Request $request, Server $server, ServerSnapshot $snapshot): RedirectResponse
    {
        $this->authorize('backup', $server);

        abort_if($snapshot->server_id !== $server->id, 404);

        if (! $snapshot->s3_key) {
            return back()->with('error', __('backups.errors.not_uploaded'));
        }

        $url = \Illuminate\Support\Facades\Storage::disk('backups')->temporaryUrl($snapshot->s3_key, now()->addMinutes(15));

        return redirect()->away($url);
    }

    public function toggleLock(Server $server, ServerSnapshot $snapshot): JsonResponse
    {
        $this->authorize('backup', $server);

        abort_if($snapshot->server_id !== $server->id, 404);

        $snapshot->forceFill(['is_locked' => ! $snapshot->is_locked])->save();

        return response()->json(['ok' => true, 'locked' => $snapshot->is_locked]);
    }

    public function destroy(Request $request, Server $server, ServerSnapshot $snapshot): RedirectResponse
    {
        $this->authorize('backup', $server);

        abort_if($snapshot->server_id !== $server->id, 404);

        if ($snapshot->is_locked) {
            return back()->with('error', __('backups.errors.locked'));
        }

        $this->backups->delete($server, $snapshot, $request->user());

        return back()->with('success', __('backups.messages.deleted'));
    }
}
