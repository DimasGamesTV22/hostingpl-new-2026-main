<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackImpersonation;
use App\Models\Server;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->withCount('servers')
            ->search($request->query('q'))
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('online'), fn ($q) => $q->online())
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => User::ROLES,
            'filters' => $request->only(['q', 'role', 'status', 'online']),
        ]);
    }

    public function show(User $user): View
    {
        $user->loadCount(['servers', 'referralsSent']);

        return view('admin.users.show', [
            'user' => $user,
            'servers' => $user->servers()->with('game', 'node')->get(),
            'transactions' => $user->transactions()->orderByDesc('created_at')->limit(50)->get(),
            'tickets' => $user->tickets()->with('department')->limit(10)->get(),
            'sessions' => $user->sessions()->orderByDesc('last_activity_at')->limit(10)->get(),
            'referrals' => $user->referralsSent()->with('referred:id,name,email')->limit(20)->get(),
            'authLogs' => \App\Models\AuditLog::where('user_id', $user->id)->orderByDesc('created_at')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_telegram' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user->fill(Arr::except($data, ['note']))->save();

        if (array_key_exists('note', $data)) {
            $user->forceFill(['note' => $data['note'] ?: null])->save();
        }

        Auditor::log('admin.user_update', 'Изменён пользователь #'.$user->id, $user, [], $data);

        return back()->with('success', __('admin.messages.user_updated'));
    }

    public function changeRole(Request $request, User $user): RedirectResponse
    {
        $this->authorize('changeRole', $user);

        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $old = $user->role;
        $user->assignRole($data['role']);

        Auditor::log('admin.user_role', "Роль {$old} → {$data['role']}", $user);

        return back()->with('success', __('admin.messages.role_changed', ['role' => $data['role']]));
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        $this->authorize('block', $user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user->forceFill([
            'status' => User::STATUS_BLOCKED,
            'block_reason' => $data['reason'],
            'blocked_at' => now(),
        ])->save();

        // Останавливаем его серверы и завершаем сессии
        Server::where('user_id', $user->id)
            ->whereNotIn('status', [Server::STATUS_STOPPED, Server::STATUS_SUSPENDED])
            ->update(['status' => Server::STATUS_SUSPENDED, 'suspended_reason' => $data['reason'], 'is_frozen' => true, 'suspended_at' => now()]);

        app(\App\Services\Users\AuthService::class)->revokeAll($user);

        Auditor::log('admin.user_block', 'Пользователь заблокирован: '.$data['reason'], $user);

        return back()->with('success', __('admin.messages.user_blocked'));
    }

    public function unblock(User $user): RedirectResponse
    {
        $this->authorize('block', $user);

        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'block_reason' => null,
            'blocked_at' => null,
        ])->save();

        Auditor::log('admin.user_unblock', 'Пользователь разблокирован', $user);

        return back()->with('success', __('admin.messages.user_unblocked'));
    }

    public function adjustBalance(Request $request, User $user): RedirectResponse
    {
        $this->authorize('adjustBalance', $user);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $amount = (float) $data['amount'];

        if ($amount === 0.0) {
            return back()->with('error', __('admin.errors.zero_amount'));
        }

        try {
            $this->wallet->apply(
                $user,
                $amount,
                $amount > 0 ? UserTransaction::TYPE_ADJUSTMENT : UserTransaction::TYPE_ADJUSTMENT,
                ($amount > 0 ? 'Начисление администратором' : 'Списание администратором').': '.$data['reason'],
                [
                    'source' => 'admin',
                    'created_by' => $request->user()->id,
                    'description' => $data['reason'],
                ],
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        Auditor::log('admin.balance_adjust', "Баланс изменён на ".money($amount).": ".$data['reason'], $user);

        return back()->with('success', __('admin.messages.balance_adjusted'));
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('impersonate', $user);

        TrackImpersonation::impersonate($user, $request->user());

        return redirect()->route('panel.dashboard')
            ->with('warning', __('admin.messages.impersonating', ['name' => $user->name]));
    }

    /** Массовые операции: блокировка, роль, удаление без серверов. */
    public function bulk(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', User::class);

        $data = $request->validate([
            'action' => ['required', Rule::in(['block', 'unblock', 'role', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $users = User::whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($users as $user) {
            if ($user->id === $request->user()->id) {
                continue;
            }

            switch ($data['action']) {
                case 'block':
                    if (! $request->user()->can('block', $user)) {
                        continue;
                    }
                    $user->forceFill([
                        'status' => User::STATUS_BLOCKED,
                        'block_reason' => $data['value'] ?? 'Массовая блокировка',
                        'blocked_at' => now(),
                    ])->save();
                    $count++;
                    break;

                case 'unblock':
                    $user->forceFill(['status' => User::STATUS_ACTIVE, 'block_reason' => null, 'blocked_at' => null])->save();
                    $count++;
                    break;

                case 'role':
                    if (! $request->user()->isSuperAdmin()) {
                        continue;
                    }
                    $user->assignRole($data['value'] ?? User::ROLE_USER);
                    $count++;
                    break;

                case 'delete':
                    if (! $request->user()->isSuperAdmin() || $user->servers()->exists()) {
                        continue;
                    }
                    $user->delete();
                    $count++;
                    break;
            }
        }

        Auditor::log('admin.bulk_action', "Массовая операция {$data['action']}: затронуто {$count}");

        return back()->with('success', __('admin.messages.bulk_done', ['count' => $count]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        if ($user->servers()->exists()) {
            return back()->with('error', __('admin.errors.has_servers'));
        }

        $user->delete();

        Auditor::log('admin.user_delete', 'Пользователь удалён #'.$user->id, null, ['id' => $user->id]);

        return redirect()->route('admin.users')->with('success', __('admin.messages.user_deleted'));
    }
}
