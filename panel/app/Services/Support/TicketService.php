<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Notify\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Тикеты поддержки.
 */
class TicketService
{
    public function __construct(private readonly Notifier $notifier) {}

    public function create(User $user, array $data): Ticket
    {
        return DB::transaction(function () use ($user, $data) {
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'server_id' => $data['server_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'subject' => mb_substr((string) $data['subject'], 0, 190),
                'category' => $data['category'] ?? null,
                'priority' => $this->normalizePriority($data['priority'] ?? 'normal', $user),
            ]);

            $this->addMessage($ticket, $user, (string) $data['message'], false, $data['attachments'] ?? []);

            return $ticket->refresh();
        });
    }

    public function reply(Ticket $ticket, User $author, string $body, bool $isInternal = false, array $attachments = []): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $author, $body, $isInternal, $attachments) {
            $isStaff = $author->isStaff();
            $message = $this->addMessage($ticket, $author, $body, $isStaff, $attachments, $isInternal);

            $ticket->forceFill([
                'last_reply_at' => now(),
                'last_reply_by_staff' => $isStaff ? now() : null,
                'unread_by_staff' => $isStaff ? $ticket->unread_by_staff : $ticket->unread_by_staff + 1,
                'unread_by_user' => $isStaff ? $ticket->unread_by_user + 1 : 0,
                'reply_count' => (int) $ticket->reply_count + 1,
            ]);

            if ($ticket->status === Ticket::STATUS_OPEN) {
                $ticket->status = $isStaff ? Ticket::STATUS_ANSWERED : Ticket::STATUS_PENDING;
            }

            if ($isStaff && $ticket->first_response_at === null) {
                $ticket->first_response_at = now();
            }

            $ticket->save();

            // Уведомляем о новом сообщении
            if ($isStaff) {
                $this->notifier->user(
                    $ticket->user,
                    'ticket.reply',
                    __('support.notices.reply', ['id' => $ticket->id]),
                    Str::limit(strip_tags($body), 200),
                    ['level' => 'info', 'link' => route('panel.tickets.show', $ticket)],
                );
            } else {
                $this->notifier->ticketReplied((int) $ticket->id, (string) $ticket->subject);
            }

            return $message;
        });
    }

    public function close(Ticket $ticket, ?User $actor = null): void
    {
        $ticket->forceFill([
            'status' => Ticket::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();
    }

    public function reopen(Ticket $ticket): void
    {
        $ticket->forceFill([
            'status' => Ticket::STATUS_OPEN,
            'closed_at' => null,
        ])->save();
    }

    public function assign(Ticket $ticket, ?User $staff): void
    {
        $ticket->forceFill(['assigned_to' => $staff?->id])->save();
    }

    public function markRead(Ticket $ticket, User $reader): void
    {
        if ($reader->isStaff()) {
            $ticket->forceFill(['unread_by_staff' => 0])->save();
        } else {
            $ticket->forceFill(['unread_by_user' => 0])->save();
        }
    }

    /**
     * Автозакрытие тикетов без ответа. Крон.
     */
    public function autoClose(): int
    {
        $days = (int) setting('hosting.support.tickets.auto_close_days', 14);

        if ($days <= 0) {
            return 0;
        }

        $tickets = Ticket::open()
            ->where('last_reply_at', '<', now()->subDays($days))
            ->orWhere(function ($q) use ($days) {
                $q->whereNull('last_reply_at')->where('created_at', '<', now()->subDays($days));
            })
            ->limit(100)
            ->get();

        foreach ($tickets as $ticket) {
            $this->close($ticket);

            $this->notifier->user(
                $ticket->user,
                'ticket.closed',
                __('support.notices.auto_closed', ['id' => $ticket->id]),
                '',
                ['level' => 'info', 'link' => route('panel.tickets.show', $ticket)],
            );
        }

        return $tickets->count();
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function addMessage(
        Ticket $ticket,
        User $author,
        string $body,
        bool $isStaff,
        array $attachments = [],
        bool $isInternal = false,
    ): TicketMessage {
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'is_staff' => $isStaff,
            'author_name' => $isStaff ? $author->name : $author->name,
            'body' => $body,
            'attachments' => $attachments ?: null,
            'is_internal_note' => $isInternal,
            'ip' => request()?->ip(),
        ]);

        $ticket->increment('messages_count');

        return $message;
    }

    /** Обычный клиент не может выставить urgent. */
    private function normalizePriority(string $priority, User $user): string
    {
        $allowed = (array) setting_array('hosting.support.tickets.priority_levels', ['low', 'normal', 'high', 'urgent']);

        if (! in_array($priority, $allowed, true)) {
            return 'normal';
        }

        if ($priority === 'urgent' && ! $user->isStaff()) {
            return 'high';
        }

        return $priority;
    }

    /** Отделы для выпадающего списка. */
    public function departments()
    {
        return TicketDepartment::where('is_active', true)->orderBy('sort')->get();
    }
}
