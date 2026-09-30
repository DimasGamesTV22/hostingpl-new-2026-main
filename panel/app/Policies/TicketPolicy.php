<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $ticket->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        if (! setting_bool('hosting.support.tickets.enabled', true)) {
            return false;
        }

        $max = (int) setting('hosting.support.tickets.max_open_per_user', 5);

        return Ticket::where('user_id', $user->id)->open()->count() < $max;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $ticket->user_id === $user->id && ! $ticket->isClosed();
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $user->isStaff();
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->atLeast(User::ROLE_MODERATOR);
    }

    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->isStaff();
    }
}
