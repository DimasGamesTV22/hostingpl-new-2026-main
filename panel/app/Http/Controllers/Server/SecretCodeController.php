<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\SecretCode;
use App\Models\Server;
use App\Services\SecretCodes\SecretCodeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SecretCodeController extends Controller
{
    public function __construct(private readonly SecretCodeResolver $resolver) {}

    public function index(Request $request, Server $server): View
    {
        $this->authorize('manageSecretCodes', $server);

        $personal = setting_bool('hosting.marketing.secret_codes.allow_personal_codes', true);

        return view('panel.servers.secret-codes', [
            'server' => $server,
            'serverCodes' => SecretCode::where('server_id', $server->id)
                ->orderByDesc('created_at')
                ->get(),
            'myCodes' => $personal
                ? SecretCode::where('user_id', $request->user()->id)
                    ->where(fn ($q) => $q->whereNull('server_id')->orWhere('server_id', $server->id))
                    ->orderByDesc('created_at')
                    ->get()
                : collect(),
            'rewards' => SecretCode::REWARDS,
            'prefixes' => $this->resolver->prefixes(),
            'enabled' => $this->resolver->isEnabled(),
            'inGame' => setting_bool('hosting.marketing.secret_codes.allow_in_game_chat', true),
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageSecretCodes', $server);

        if (! $this->resolver->isEnabled()) {
            return back()->with('error', __('secret_codes.errors.disabled'));
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'hint' => ['nullable', 'string', 'max:190'],
            'reward_type' => ['required', Rule::in(SecretCode::REWARDS)],
            'reward_value' => ['required', 'numeric', 'min:0', 'max:100000'],
            'reward_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reward_memory_mb' => ['nullable', 'integer', 'min:0', 'max:65536'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_player_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scope' => ['required', Rule::in(['server', 'personal'])],
        ]);

        $payload = [
            'hint' => $data['hint'] ?? null,
            'reward_type' => $data['reward_type'],
            'reward_value' => $data['reward_value'],
            'reward_days' => (int) ($data['reward_days'] ?? 0),
            'reward_memory_mb' => (int) ($data['reward_memory_mb'] ?? 0),
            'max_uses' => $data['max_uses'] ?? 1,
            'per_player_limit' => $data['per_player_limit'] ?? 1,
            'expires_at' => null,
        ];

        if ($data['scope'] === 'personal') {
            if (! setting_bool('hosting.marketing.secret_codes.allow_personal_codes', true)) {
                return back()->with('error', __('secret_codes.errors.personal_disabled'));
            }

            $this->resolver->createPersonalCode(
                $request->user(),
                (string) ($data['code'] ?? \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8))),
                $data['reward_type'],
                (float) $data['reward_value'],
                $payload,
            );
        } else {
            SecretCode::create(array_merge($payload, [
                'code' => mb_strtoupper(trim((string) ($data['code'] ?? \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8))))),
                'server_id' => $server->id,
                'game_id' => $server->game_id,
                'created_by' => $request->user()->id,
            ]));
        }

        $this->resolver->flushCache($server);

        $this->logs($server, __('secret_codes.events.created'), $request->user());

        return back()->with('success', __('secret_codes.messages.created'));
    }

    public function destroy(Request $request, Server $server, SecretCode $secretCode): RedirectResponse
    {
        $this->authorize('manageSecretCodes', $server);

        $isOwner = $secretCode->user_id === $request->user()->id;
        $isServer = $secretCode->server_id === $server->id;

        abort_unless($isOwner || $isServer || $request->user()->isAdmin(), 403);

        $secretCode->forceFill(['is_active' => false])->save();

        $this->resolver->flushCache($server);

        $this->logs($server, __('secret_codes.events.disabled', ['code' => $secretCode->code]), $request->user());

        return back()->with('success', __('secret_codes.messages.disabled'));
    }

    public function usages(Request $request, Server $server): View
    {
        $this->authorize('manageSecretCodes', $server);

        return view('panel.servers.secret-code-usages', [
            'server' => $server,
            'usages' => \App\Models\SecretCodeUse::where('server_id', $server->id)
                ->with('secretCode')
                ->orderByDesc('created_at')
                ->paginate(50),
        ]);
    }

    private function logs(Server $server, string $title, $user): void
    {
        app(\App\Services\Servers\Provisioner::class)->log($server, 'setting', $title, 'info', [], $user);
    }
}
