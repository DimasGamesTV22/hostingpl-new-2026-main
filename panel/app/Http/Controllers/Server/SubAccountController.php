<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSubAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubAccountController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        $this->authorize('manageSubAccounts', $server);

        return view('panel.servers.sub-accounts', [
            'server' => $server,
            'accounts' => $server->subAccounts()->orderByDesc('is_active')->get(),
            'roles' => ServerSubAccount::ROLES,
            'permissions' => ServerSubAccount::PERMISSIONS,
            'max' => (int) setting('hosting.sub_accounts.max_per_server', 10),
            'featuresEnabled' => setting_bool('hosting.sub_accounts.enabled', true),
            'perServer' => setting_bool('hosting.sub_accounts.per_server_mode', true),
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageSubAccounts', $server);

        if (! setting_bool('hosting.sub_accounts.enabled', true)) {
            return back()->with('error', __('servers.errors.sub_accounts_disabled'));
        }

        $max = (int) setting('hosting.sub_accounts.max_per_server', 10);

        if ($server->subAccounts()->count() >= $max) {
            return back()->with('error', __('servers.errors.sub_accounts_limit', ['max' => $max]));
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(ServerSubAccount::ROLES)],
            'password' => ['nullable', 'string', 'min:8'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(ServerSubAccount::PERMISSIONS))],
        ]);

        $existing = \App\Models\User::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->first();

        // Если пользователя нет — создаём с временным паролем
        $user = $existing ?: \App\Models\User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => Hash::make($data['password'] ?: \Illuminate\Support\Str::random(20)),
            'email_verified_at' => now(),
            'referral_code' => \App\Models\User::generateReferralCode(),
        ]);

        if ($server->subAccounts()->where('email', $user->email)->exists()) {
            return back()->with('error', __('servers.errors.sub_account_exists'));
        }

        $permissions = $data['permissions'] ?? ServerSubAccount::defaultPermissions($data['role']);

        $server->subAccounts()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $data['name'],
            'role' => $data['role'],
            'permissions' => $permissions,
            'invited_by' => $request->user()->id,
        ]);

        $this->provisionerLog($server, __('servers.events.sub_account_added', ['name' => $data['name']]), $request->user());

        return back()->with('success', __('servers.messages.sub_account_added'));
    }

    public function update(Request $request, Server $server, ServerSubAccount $subAccount): JsonResponse
    {
        $this->authorize('manageSubAccounts', $server);

        abort_if($subAccount->server_id !== $server->id, 404);

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(ServerSubAccount::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(array_keys(ServerSubAccount::PERMISSIONS))],
        ]);

        $subAccount->forceFill(array_filter([
            'role' => $data['role'] ?? null,
            'permissions' => $data['permissions'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('is_active', $data)) {
            $subAccount->forceFill(['is_active' => $request->boolean('is_active')]);
        }

        $subAccount->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Server $server, ServerSubAccount $subAccount): RedirectResponse
    {
        $this->authorize('manageSubAccounts', $server);

        abort_if($subAccount->server_id !== $server->id, 404);

        $name = $subAccount->name;
        $subAccount->delete();

        $this->provisionerLog($server, __('servers.events.sub_account_removed', ['name' => $name]), auth()->user());

        return back()->with('success', __('servers.messages.sub_account_removed'));
    }

    private function provisionerLog(Server $server, string $title, $user): void
    {
        app(\App\Services\Servers\Provisioner::class)->log($server, 'sub_account', $title, 'info', [], $user);
    }
}
