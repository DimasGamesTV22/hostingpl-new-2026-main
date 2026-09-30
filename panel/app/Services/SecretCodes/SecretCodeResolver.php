<?php

declare(strict_types=1);

namespace App\Services\SecretCodes;

use App\Models\SecretCode;
use App\Models\SecretCodeUse;
use App\Models\Server;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Notify\Notifier;
use App\Services\Servers\Provisioner;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Секретные коды игроков (аналог модуля «Секретные коды» в HOSTINPL).
 *
 * Схема работы:
 *   1. Агент настроен на перехват строк чата, начинающихся с префиксов
 *      (//, !, /promo) — см. config/hosting.php → marketing.secret_codes.prefixes.
 *   2. Агент шлёт событие `chat.message` с текстом строки.
 *   3. Панель проверяет, не является ли строка секретным кодом для этого сервера.
 *   4. Если да — начисляет бонус, пишет в консоль ответ и уведомляет игрока.
 */
class SecretCodeResolver
{
    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly ServerEventLogger $events,
        private readonly ConsoleBroadcaster $console,
        private readonly Notifier $notifier,
    ) {}

    public function isEnabled(): bool
    {
        return setting_bool('hosting.marketing.secret_codes.enabled', true);
    }

    /** Префиксы строк, которые агент перехватывает. */
    public function prefixes(): array
    {
        return (array) setting('hosting.marketing.secret_codes.prefixes', ['//', '!']);
    }

    /**
     * Разбор перехваченной строки чата.
     *
     * @param  array<string, mixed>  $data  {server_id, text, player_id, player_name}
     * @return array{matched: bool, code: ?SecretCode, message: ?string}
     */
    public function handleChatMessage(array $data): array
    {
        if (! $this->isEnabled() || ! setting_bool('hosting.marketing.secret_codes.allow_in_game_chat', true)) {
            return ['matched' => false, 'code' => null, 'message' => null];
        }

        $text = trim((string) ($data['text'] ?? ''));
        $code = $this->extractCode($text);

        if ($code === null) {
            return ['matched' => false, 'code' => null, 'message' => null];
        }

        $server = Server::find((int) ($data['server_id'] ?? 0));

        if (! $server) {
            return ['matched' => false, 'code' => null, 'message' => null];
        }

        // Кэш кодов сервера на минуту, чтобы не ходить в БД на каждое сообщение
        $secret = $this->findForServer($server, $code);

        if (! $secret) {
            return ['matched' => true, 'code' => null, 'message' => null];
        }

        $playerId = $data['player_id'] ?? null;
        $playerName = $data['player_name'] ?? null;

        if (! $secret->canBeUsedBy($playerId !== null ? (string) $playerId : null)) {
            $reason = $secret->isExhausted()
                ? __('secret_codes.errors.exhausted')
                : __('secret_codes.errors.already_used');

            return [
                'matched' => true,
                'code' => $secret,
                'message' => $this->chatMessage($reason, 'ff5555'),
            ];
        }

        $reward = $this->grant($secret, $server, $playerId, $playerName, $data['ip'] ?? null);

        $this->events->log(
            $server,
            'setting',
            __('secret_codes.events.redeemed', ['code' => $secret->code, 'player' => $playerName ?? 'игрок']),
            'info',
            ['reward' => $reward],
        );

        return [
            'matched' => true,
            'code' => $secret,
            'message' => $this->chatMessage(
                __('secret_codes.messages.success', [
                    'reward' => $secret->rewardLabel(),
                    'hint' => $secret->hint ?? '',
                ]),
                '55ff55',
            ),
        ];
    }

    /**
     * Начислить награду.
     *
     * @return array<string, mixed>
     */
    public function grant(SecretCode $secret, Server $server, ?string $playerId, ?string $playerName, ?string $ip = null): array
    {
        $user = $server->user;
        $applied = [];

        try {
            match ($secret->reward_type) {
                SecretCode::REWARD_MONEY, SecretCode::REWARD_CREDIT => (function () use ($user, $secret, &$applied) {
                    app(\App\Services\Billing\WalletService::class)->credit(
                        $user,
                        (float) $secret->reward_value,
                        \App\Models\UserTransaction::TYPE_BONUS,
                        __('secret_codes.titles.reward', ['code' => $secret->code]),
                        ['source' => 'secret_code', 'server_id' => $server->id, 'reference' => $secret->code],
                    );

                    $applied['money'] = (float) $secret->reward_value;
                })(),

                SecretCode::REWARD_SLOTS => (function () use ($server, $secret, &$applied) {
                    $this->provisioner->updateResources(
                        $server,
                        ['slots' => (int) $server->slots + (int) $secret->reward_value],
                    );

                    $applied['slots'] = (int) $secret->reward_value;
                })(),

                SecretCode::REWARD_DAYS => (function () use ($server, $secret, &$applied) {
                    $server->forceFill([
                        'expires_at' => ($server->expires_at ?? now())->copy()->addDays((int) $secret->reward_days),
                    ])->save();

                    $applied['days'] = (int) $secret->reward_days;
                })(),

                SecretCode::REWARD_MEMORY => (function () use ($server, $secret, &$applied) {
                    $this->provisioner->updateResources(
                        $server,
                        ['memory_mb' => (int) $server->memory_mb + (int) $secret->reward_memory_mb],
                    );

                    $applied['memory_mb'] = (int) $secret->reward_memory_mb;
                })(),

                default => null,
            };

            $secret->increment('used_count');

            SecretCodeUse::create([
                'secret_code_id' => $secret->id,
                'server_id' => $server->id,
                'user_id' => $user->id,
                'player_id' => $playerId,
                'player_name' => $playerName,
                'ip' => $ip,
                'reward_applied' => $applied,
                'response' => $secret->rewardLabel(),
            ]);

            $this->notifier->user(
                $user,
                'secret_code',
                __('secret_codes.titles.redeemed', ['code' => $secret->code, 'player' => $playerName ?? 'игрок']),
                $secret->rewardLabel(),
                ['level' => 'success', 'telegram' => true, 'email' => false],
            );

            return $applied;
        } catch (\Throwable $e) {
            Log::warning('Не удалось начислить секретный код: '.$e->getMessage(), [
                'code' => $secret->id,
                'server' => $server->id,
            ]);

            return [];
        }
    }

    /** Коды, которые нужно отправить агенту для проверки (для админки и кэша). */
    public function findForServer(Server $server, string $code): ?SecretCode
    {
        $cacheKey = "gamedock:secret-codes:{$server->id}";

        $codes = Cache::rememberForever($cacheKey, function () use ($server) {
            return SecretCode::forServer($server)
                ->get(['id', 'code', 'hint', 'server_id', 'user_id', 'game_id', 'reward_type',
                    'reward_value', 'reward_days', 'reward_memory_mb', 'max_uses', 'used_count',
                    'per_player_limit', 'min_rank_level', 'is_active', 'expires_at'])
                ->keyBy('code')
                ->map(fn (SecretCode $c) => $c->getAttributes())
                ->all();
        });

        $normalized = mb_strtoupper(trim($code));
        $found = $codes[$normalized] ?? null;

        if (! $found) {
            return null;
        }

        $secret = new SecretCode();
        $secret->forceFill($found);
        $secret->exists = true;

        return $secret;
    }

    public function flushCache(?Server $server = null): void
    {
        if ($server) {
            Cache::forget("gamedock:secret-codes:{$server->id}");

            return;
        }

        // Полный сброс — коды не хранятся в одном ключе, поэтому просто
        // помечаем версию, чтобы кэш пересоздался по TTL
        Cache::put('gamedock:secret-codes:version', (string) Str::random(8), now()->addMinutes(5));
    }

    /** Достать код из строки вида «//PROMO» или «!code123». */
    public function extractCode(string $text): ?string
    {
        $text = trim($text);

        foreach ($this->prefixes() as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $code = trim(substr($text, strlen($prefix)));

                // Отрезаем возможные аргументы: «PROMO extra»
                $code = trim((string) preg_split('/\s+/', $code)[0]);

                return $code !== '' ? $code : null;
            }
        }

        return null;
    }

    /** Готовая строка для отправки в игровой чат. */
    private function chatMessage(string $text, string $color = 'ffffff'): string
    {
        // SAMP/MTA/Rust понимают hex-цвет в квадратных скобках: {RRGGBB}текст
        return '{'.$color.'} '.$text;
    }

    /**
     * Создать персональный код пользователя.
     */
    public function createPersonalCode(
        \App\Models\User $user,
        string $code,
        string $rewardType,
        float $value,
        array $options = [],
    ): SecretCode {
        if (! setting_bool('hosting.marketing.secret_codes.allow_personal_codes', true)) {
            throw new \RuntimeException(__('secret_codes.errors.personal_disabled'));
        }

        return SecretCode::create([
            'code' => mb_strtoupper(trim($code)),
            'hint' => $options['hint'] ?? null,
            'server_id' => $options['server_id'] ?? null,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'reward_type' => $rewardType,
            'reward_value' => $value,
            'reward_days' => (int) ($options['days'] ?? 0),
            'reward_memory_mb' => (int) ($options['memory_mb'] ?? 0),
            'max_uses' => $options['max_uses'] ?? 1,
            'per_player_limit' => $options['per_player_limit'] ?? 1,
            'is_active' => true,
            'expires_at' => $options['expires_at'] ?? null,
        ]);
    }
}
