<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Правила доступа к игровому серверу.
 * Учитывает владельца, роли админов и права саб-аккаунта.
 */
class ServerPolicy
{
    /** Права саб-аккаунта, требуемые для операции. */
    private const PERMISSION_MAP = [
        'view' => 'console.read',
        'console.write' => 'console.write',
        'control' => 'console.control',
        'files.read' => 'files.read',
        'files.write' => 'files.write',
        'files.delete' => 'files.delete',
        'backup.create' => 'backup.create',
        'backup.restore' => 'backup.restore',
        'settings.read' => 'settings.read',
        'settings.write' => 'settings.write',
    ];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        return $this->isOwner($user, $server)
            || $user->isAdmin()
            || $this->subAccountHas($user, $server, 'console.read');
    }

    public function create(User $user): bool
    {
        return $user->status === User::STATUS_ACTIVE && $user->serverQuotaLeft() > 0;
    }

    public function update(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $this->isOwner($user, $server)) {
            return $this->subAccountHas($user, $server, 'settings.write');
        }

        // Замороженный сервер нельзя менять
        return ! $server->is_frozen;
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->isOwner($user, $server) || $user->isAdmin();
    }

    public function power(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isOwner($user, $server)) {
            return ! $server->is_frozen;
        }

        return $this->subAccountHas($user, $server, 'console.control');
    }

    public function console(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwner($user, $server) || $this->subAccountHas($user, $server, 'console.write');
    }

    public function files(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwner($user, $server) || $this->subAccountHas($user, $server, 'files.read');
    }

    public function writeFiles(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwner($user, $server) || $this->subAccountHas($user, $server, 'files.write');
    }

    public function backup(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwner($user, $server) || $this->subAccountHas($user, $server, 'backup.create');
    }

    public function restore(User $user, Server $server): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwner($user, $server) || $this->subAccountHas($user, $server, 'backup.restore');
    }

    public function manageSubAccounts(User $user, Server $server): bool
    {
        return $this->isOwner($user, $server) || $user->isAdmin();
    }

    public function manageSecretCodes(User $user, Server $server): bool
    {
        return $this->isOwner($user, $server) || $user->isAdmin();
    }

    public function reinstall(User $user, Server $server): bool
    {
        return $this->isOwner($user, $server) || $user->isAdmin();
    }

    public function transfer(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function isOwner(User $user, Server $server): bool
    {
        return $server->user_id === $user->id;
    }

    private function subAccountHas(User $user, Server $server, string $permission): bool
    {
        if (! $server->sub_accounts_enabled) {
            return false;
        }

        $sub = $server->subAccounts()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $sub !== null && $sub->hasPermission($permission);
    }
}
