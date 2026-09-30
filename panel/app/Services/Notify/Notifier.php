<?php

declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Node;
use App\Models\PanelNotification;
use App\Models\Server;
use App\Models\User;
use App\Services\Mail\Mailer;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка уведомлений: запись в панель + письмо + Telegram.
 * Уровень управляет тем, какие каналы используются.
 */
class Notifier
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly Mailer $mailer,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function user(User $user, string $type, string $title, string $body = '', array $options = []): void
    {
        $level = $options['level'] ?? 'info';

        try {
            PanelNotification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => mb_substr($title, 0, 190),
                'body' => mb_substr($body, 0, 1000) ?: null,
                'link' => $options['link'] ?? null,
                'level' => $level,
                'meta' => $options['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Не удалось создать уведомление: '.$e->getMessage());
        }

        if (($options['email'] ?? true) && $user->email) {
            $this->mailer->to($user->email)->send($options['mail_subject'] ?? $title, $body ?: $title);
        }

        if (($options['telegram'] ?? true) && filled($user->contact_telegram)) {
            $this->telegram->sendMessage($this->escape($title).($body ? "\n\n".$this->escape($body) : ''), [
                'chat_id' => $user->contact_telegram,
            ]);
        }
    }

    /** @param array<string, mixed> $meta */
    public function admins(string $type, string $title, string $body = '', string $level = 'warning', array $meta = []): void
    {
        $this->telegram->sendMessage(
            '<b>'.e($title).'</b>'.($body ? "\n\n".e($body) : ''),
        );

        Log::info('[admins] '.$title, $meta);
    }

    public function serverDown(Server $server): void
    {
        $this->user(
            $server->user,
            'server.down',
            __('notifications.server_down', ['name' => $server->name]),
            $server->status_reason ?? '',
            [
                'level' => 'danger',
                'link' => route('panel.servers.show', $server),
                'telegram' => true,
            ],
        );

        $this->telegram->sendMessage($this->telegram->serverDown($server));
    }

    public function serverRestored(Server $server): void
    {
        $this->user(
            $server->user,
            'server.restored',
            __('notifications.server_restored', ['name' => $server->name]),
            '',
            [
                'level' => 'success',
                'link' => route('panel.servers.show', $server),
                'telegram' => true,
            ],
        );

        $this->telegram->sendMessage($this->telegram->serverRestored($server));
    }

    public function serverExpiring(Server $server, int $daysLeft): void
    {
        $this->user(
            $server->user,
            'server.expiring',
            __('notifications.server_expiring', ['name' => $server->name, 'days' => $daysLeft]),
            '',
            [
                'level' => 'warning',
                'link' => route('panel.billing.deposit.create', ['amount' => 100]),
                'telegram' => true,
            ],
        );

        $this->telegram->sendMessage($this->telegram->expiringSoon($server, $daysLeft));
    }

    public function serverSuspended(Server $server, string $reason): void
    {
        $this->user(
            $server->user,
            'server.suspended',
            __('notifications.server_suspended', ['name' => $server->name]),
            $reason,
            [
                'level' => 'danger',
                'link' => route('panel.billing.deposit.create'),
                'telegram' => true,
            ],
        );

        $this->telegram->sendMessage($this->telegram->suspended($server, $reason));
    }

    public function lowBalance(User $user, float $threshold): void
    {
        $this->user(
            $user,
            'billing.low_balance',
            __('notifications.low_balance', ['balance' => money($user->balance)]),
            '',
            ['level' => 'warning', 'telegram' => true],
        );
    }

    public function nodeOffline(Node $node): void
    {
        $this->admins('node.offline', __('notifications.node_offline', ['name' => $node->name]), '', 'danger');
        $this->telegram->sendMessage($this->telegram->nodeOffline($node->name));
    }

    public function backupReady(Server $server, string $name, int $bytes): void
    {
        $this->user(
            $server->user,
            'backup.done',
            __('notifications.backup_ready', ['name' => $name]),
            bytes_human($bytes),
            [
                'level' => 'success',
                'link' => route('panel.server.backups', $server),
                'telegram' => true,
            ],
        );
    }

    public function ticketReplied(int $ticketId, string $subject): void
    {
        $this->admins('ticket.reply', 'Новый ответ в тикете #'.$ticketId, $subject, 'info');
    }

    private function escape(string $text): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
    }
}
