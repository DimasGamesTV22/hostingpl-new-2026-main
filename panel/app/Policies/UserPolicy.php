<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isStaff() || $user->id === $target->id;
    }

    public function update(User $user, User $target): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Самостоятельно можно менять только непривилегированные поля
        return $user->id === $target->id;
    }

    public function changeRole(User $user, User $target): bool
    {
        return $user->isSuperAdmin();
    }

    public function block(User $user, User $target): bool
    {
        if ($user->isAdmin() && ! $user->isSuperAdmin()) {
            return ! $target->isAdmin() && ! $target->isSuperAdmin();
        }

        return $user->isSuperAdmin() && $user->id !== $target->id;
    }

    public function adjustBalance(User $user, User $target): bool
    {
        return $user->atLeast(User::ROLE_ADMIN);
    }

    public function impersonate(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isSuperAdmin() && $user->id !== $target->id;
    }
}
