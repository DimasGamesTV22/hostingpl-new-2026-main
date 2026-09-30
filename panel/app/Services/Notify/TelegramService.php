<?php

declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщений в Telegram.
 *
 * Используется алертами, ботом и уведомлениями панели. Работает и синхронно
 * (в веб-интерфейсе), и через очередь (для алертов).
 */
class TelegramService
{
    public const API = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $adminChatId = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return filled($this->token());
    }

    public function token(): ?string
    {
        return $this->token ?: (string) setting('telegram.bot_token', env('GD_TELEGRAM_BOT_TOKEN', '')) ?: null;
    }

    public function adminChatId(): ?string
    {
        return $this->adminChatId ?: (string) setting('telegram.admin_chat_id', env('GD_TELEGRAM_ADMIN_CHAT_ID', '')) ?: null;
    }

    /**
     * Отправить сообщение.
     *
     * @param  array<string, mixed>  $options  chat_id, parse_mode, reply_markup, disable_notification
     * @return array{ok: bool, error: ?string, result: mixed}
     */
    public function sendMessage(string $text, array $options = []): array
    {
        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => 'Telegram-бот не настроен', 'result' => null];
        }

        $chatId = $options['chat_id'] ?? $this->adminChatId();

        if (! $chatId) {
            return ['ok' => false, 'error' => 'Не указан чат для Telegram', 'result' => null];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $options['parse_mode'] ?? 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (isset($options['reply_markup'])) {
            $payload['reply_markup'] = $options['reply_markup'];
        }

        if (! empty($options['disable_notification'])) {
            $payload['disable_notification'] = true;
        }

        return $this->call('sendMessage', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string, result: mixed}
     */
    public function call(string $method, array $payload): array
    {
        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => 'Telegram-бот не настроен', 'result' => null];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::asForm()
                ->timeout(10)
                ->post(self::API.$token.'/'.$method, $payload);

            $body = $response->json();

            if (! $response->successful() || ($body['ok'] ?? false) !== true) {
                $error = $body['description'] ?? 'HTTP '.$response->status();

                Log::warning('Telegram API error', ['method' => $method, 'error' => $error]);

                return ['ok' => false, 'error' => $error, 'result' => null];
            }

            return ['ok' => true, 'error' => null, 'result' => $body['result'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('Telegram request failed: '.$e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage(), 'result' => null];
        }
    }

    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    // ── Шаблоны сообщений ───────────────────────────────────────────────

    public function serverDown(Server $server): string
    {
        return sprintf(
            "🔴 <b>Сервер упал</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n"
            ."<b>Игра:</b> {$server->game->name}\n"
            ."<b>Нода:</b> ".($server->node?->name ?? '—')."\n"
            ."<b>Адрес:</b> <code>{$server->address}</code>\n"
            ."<b>Причина:</b> ".($server->status_reason ?? 'неизвестна'),
        );
    }

    public function serverRestored(Server $server): string
    {
        return sprintf(
            "🟢 <b>Сервер поднялся</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n"
            ."<b>Игра:</b> {$server->game->name}\n"
            ."<b>Аптайм:</b> ".duration_human($server->uptime_seconds),
        );
    }

    public function highResources(Server $server, array $usage): string
    {
        $rows = '';
        foreach ($usage as $label => $value) {
            $rows .= "• <b>{$label}:</b> {$value}%\n";
        }

        return sprintf(
            "⚠️ <b>Высокая нагрузка</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n{$rows}\n"
            ."{$server->address}",
        );
    }

    public function expiringSoon(Server $server, int $days): string
    {
        return sprintf(
            "⏳ <b>Сервер скоро закончится</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n"
            ."<b>Истекает через:</b> {$days} ".trans_choice('день|дня|дней', $days, [], 'ru')."\n"
            ."Пополните баланс, чтобы сервер не был остановлен.",
        );
    }

    public function suspended(Server $server, string $reason): string
    {
        return sprintf(
            "⏸ <b>Сервер приостановлен</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n"
            ."<b>Причина:</b> {$reason}\n\n"
            ."Данные хранятся ".setting('hosting.billing.delete_after_stop_days', 14)." дней. Пополните баланс для восстановления.",
        );
    }

    public function lowBalance(User $user, float $threshold): string
    {
        return sprintf(
            "💳 <b>Низкий баланс</b>\n\n"
            ."На вашем счёте: <b>%s</b>\n"
            ."Если баланс станет нулевым, серверы будут остановлены через %d дн.",
            money($user->balance),
            (int) setting('hosting.billing.grace_period_days', 3),
        );
    }

    public function nodeOffline(string $nodeName): string
    {
        return sprintf(
            "🛑 <b>Нода недоступна</b>\n\n"
            ."Нода: <b>%s</b>\n"
            ."Серверы на ней не отвечают. Проверьте агент и ресурсы ноды.",
            $nodeName,
        );
    }

    public function backupCompleted(Server $server, string $name, string $size): string
    {
        return sprintf(
            "💾 <b>Бэкап готов</b>\n\n"
            ."<b>Сервер:</b> {$server->name}\n"
            ."<b>Имя:</b> {$name}\n"
            ."<b>Размер:</b> {$size}",
        );
    }
}
