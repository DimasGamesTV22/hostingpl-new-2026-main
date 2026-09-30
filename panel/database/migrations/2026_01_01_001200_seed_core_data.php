<?php

declare(strict_types=1);

use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\PromoCode;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── Настройки по умолчанию (зеркало config/hosting.php) ───────────
        $settings = [
            ['group' => 'general', 'key' => 'hosting.node_mode', 'value' => 'auto', 'type' => 'string',
                'label' => 'Режим работы с нодами', 'options' => json_encode(['single', 'manual', 'auto']), 'sort' => 1],
            ['group' => 'general', 'key' => 'hosting.runtime.default', 'value' => 'docker', 'type' => 'string',
                'label' => 'Рантайм по умолчанию', 'options' => json_encode(['docker', 'podman', 'lxc', 'native']), 'sort' => 2],
            ['group' => 'general', 'key' => 'hosting.branding.name', 'value' => 'GameDock', 'type' => 'string',
                'label' => 'Название панели', 'sort' => 3],
            ['group' => 'general', 'key' => 'hosting.branding.support_email', 'value' => 'support@example.com', 'type' => 'string',
                'label' => 'Почта поддержки', 'sort' => 4],
            ['group' => 'general', 'key' => 'hosting.account.max_servers_per_user', 'value' => '20', 'type' => 'int',
                'label' => 'Максимум серверов на пользователя', 'sort' => 5],
            ['group' => 'general', 'key' => 'hosting.locale.default', 'value' => 'ru', 'type' => 'string',
                'label' => 'Язык по умолчанию', 'options' => json_encode(['ru', 'en']), 'sort' => 6],

            ['group' => 'auth', 'key' => 'hosting.auth.registration_enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Регистрация открыта', 'sort' => 1],
            ['group' => 'auth', 'key' => 'hosting.auth.require_email_verification', 'value' => '1', 'type' => 'bool',
                'label' => 'Требовать подтверждение email', 'sort' => 2],
            ['group' => 'auth', 'key' => 'hosting.auth.recaptcha.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Защита форм (Turnstile)', 'sort' => 3],
            ['group' => 'auth', 'key' => 'hosting.auth.two_factor.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Двухфакторная аутентификация', 'sort' => 4],
            ['group' => 'auth', 'key' => 'hosting.auth.two_factor.required_for_roles', 'value' => '["superadmin","admin"]',
                'type' => 'json', 'label' => 'Обязательный 2FA для ролей', 'sort' => 5],

            ['group' => 'billing', 'key' => 'hosting.billing.currency', 'value' => 'RUB', 'type' => 'string',
                'label' => 'Валюта', 'sort' => 1],
            ['group' => 'billing', 'key' => 'hosting.billing.grace_period_days', 'value' => '3', 'type' => 'int',
                'label' => 'Льготный период (дней) при нулевом балансе', 'sort' => 2],
            ['group' => 'billing', 'key' => 'hosting.billing.warn_before_expiry_days', 'value' => '3', 'type' => 'int',
                'label' => 'Предупреждать за сколько дней', 'sort' => 3],
            ['group' => 'billing', 'key' => 'hosting.billing.stop_on_zero_balance', 'value' => '1', 'type' => 'bool',
                'label' => 'Останавливать серверы при нулевом балансе', 'sort' => 4],
            ['group' => 'billing', 'key' => 'hosting.billing.delete_after_stop_days', 'value' => '14', 'type' => 'int',
                'label' => 'Удалять данные через N дней после остановки', 'sort' => 5],
            ['group' => 'billing', 'key' => 'hosting.billing.min_deposit', 'value' => '100', 'type' => 'float',
                'label' => 'Минимальная сумма пополнения', 'sort' => 6],

            ['group' => 'marketing', 'key' => 'hosting.marketing.promo_discount.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Промокоды со скидкой', 'sort' => 1],
            ['group' => 'marketing', 'key' => 'hosting.marketing.promo_duration.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Промокоды на срок', 'sort' => 2],
            ['group' => 'marketing', 'key' => 'hosting.marketing.promo_bonus.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Промокоды с бонусом', 'sort' => 3],
            ['group' => 'marketing', 'key' => 'hosting.marketing.referral.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Реферальная программа', 'sort' => 4],
            ['group' => 'marketing', 'key' => 'hosting.marketing.secret_codes.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Секретные коды для игроков', 'sort' => 5],
            ['group' => 'marketing', 'key' => 'hosting.marketing.secret_codes.allow_in_game_chat', 'value' => '1', 'type' => 'bool',
                'label' => 'Коды работают в игровом чате', 'sort' => 6],
            ['group' => 'marketing', 'key' => 'hosting.marketing.secret_codes.allow_personal_codes', 'value' => '1', 'type' => 'bool',
                'label' => 'Личные коды пользователей', 'sort' => 7],
            ['group' => 'marketing', 'key' => 'hosting.marketing.trial.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Тестовый период при регистрации', 'sort' => 8],
            ['group' => 'marketing', 'key' => 'hosting.marketing.trial.days', 'value' => '3', 'type' => 'int',
                'label' => 'Дней тестового периода', 'sort' => 9],

            ['group' => 'monitoring', 'key' => 'hosting.monitoring.public.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Публичный мониторинг на главной', 'sort' => 1],
            ['group' => 'monitoring', 'key' => 'hosting.monitoring.alerts.server_down', 'value' => '1', 'type' => 'bool',
                'label' => 'Алерт на падение сервера', 'sort' => 2],

            ['group' => 'payments', 'key' => 'hosting.payments.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Приём оплаты включён', 'sort' => 1],
            ['group' => 'payments', 'key' => 'hosting.payments.methods.wallet.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Оплата с баланса', 'sort' => 2],
            ['group' => 'payments', 'key' => 'hosting.payments.methods.yookassa.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'ЮKassa', 'sort' => 3],
            ['group' => 'payments', 'key' => 'hosting.payments.methods.cryptobot.enabled', 'value' => '1', 'type' => 'bool',
                'label' => 'Криптобот (Telegram)', 'sort' => 4],
            ['group' => 'payments', 'key' => 'hosting.payments.methods.tinkoff.enabled', 'value' => '0', 'type' => 'bool',
                'label' => 'Т-Банк', 'sort' => 5],
        ];

        foreach ($settings as $s) {
            Setting::create($s);
        }

        // ── Роли (выпадающий список в админке) ────────────────────────────
        Setting::create(['group' => 'general', 'key' => 'roles', 'value' => json_encode([
            'user' => ['title' => 'Пользователь', 'color' => 'gray', 'level' => 0],
            'support' => ['title' => 'Поддержка', 'color' => 'blue', 'level' => 10],
            'moderator' => ['title' => 'Модератор', 'color' => 'indigo', 'level' => 20],
            'admin' => ['title' => 'Администратор', 'color' => 'yellow', 'level' => 30],
            'superadmin' => ['title' => 'Владелец', 'color' => 'red', 'level' => 100],
        ]), 'type' => 'json', 'label' => 'Роли и их уровни']);

        // ── Администратор по умолчанию ────────────────────────────────────
        $admin = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Администратор',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin12345'),
            'role' => 'superadmin',
            'is_staff' => true,
            'email_verified_at' => $now,
            'referral_code' => strtoupper(Str::random(8)),
            'balance' => 0,
            'status' => 'active',
            'register_ip' => '127.0.0.1',
            'last_login_at' => $now,
        ]);

        // ── Тарифы ───────────────────────────────────────────────────────
        $tariffs = [
            [
                'slug' => 'trial', 'name' => 'Тестовый', 'model' => 'package', 'price' => 0,
                'billing_period' => 'day', 'duration_days' => 3, 'slots' => 10, 'memory_mb' => 1024,
                'cpu_percent' => 30, 'disk_mb' => 5120, 'network_mbps' => 10, 'pids' => 256,
                'backups' => 1, 'max_servers' => 1, 'is_trial' => true, 'is_public' => false,
                'sort' => 0, 'description' => 'Бесплатно на 3 дня, чтобы попробовать',
                'short_description' => '3 дня бесплатно', 'badge' => 'Тест',
            ],
            [
                'slug' => 'start', 'name' => 'Start', 'model' => 'package', 'price' => 149,
                'billing_period' => 'month', 'duration_days' => 30, 'slots' => 10, 'memory_mb' => 2048,
                'cpu_percent' => 50, 'disk_mb' => 10240, 'network_mbps' => 25, 'pids' => 512,
                'backups' => 3, 'max_servers' => 1, 'is_popular' => false, 'sort' => 1,
                'features' => json_encode([
                    ['icon' => 'server', 'text' => '1 игровой сервер'],
                    ['icon' => 'cpu', 'text' => '2 ГБ RAM, 50% CPU'],
                    ['icon' => 'database', 'text' => '10 ГБ диска'],
                    ['icon' => 'users', 'text' => '10 слотов'],
                ]),
            ],
            [
                'slug' => 'classic', 'name' => 'Classic', 'model' => 'package', 'price' => 349,
                'billing_period' => 'month', 'duration_days' => 30, 'slots' => 30, 'memory_mb' => 4096,
                'cpu_percent' => 100, 'disk_mb' => 30720, 'network_mbps' => 50, 'pids' => 1024,
                'backups' => 5, 'max_servers' => 2, 'is_popular' => true, 'sort' => 2,
                'allow_custom_port' => true,
                'features' => json_encode([
                    ['icon' => 'server', 'text' => '2 игровых сервера'],
                    ['icon' => 'cpu', 'text' => '4 ГБ RAM, 100% CPU'],
                    ['icon' => 'database', 'text' => '30 ГБ диска'],
                    ['icon' => 'users', 'text' => '30 слотов'],
                    ['icon' => 'shield', 'text' => 'Свой порт'],
                ]),
            ],
            [
                'slug' => 'pro', 'name' => 'Pro', 'model' => 'hybrid', 'price' => 699,
                'billing_period' => 'month', 'duration_days' => 30, 'slots' => 60, 'memory_mb' => 6144,
                'cpu_percent' => 150, 'disk_mb' => 51200, 'network_mbps' => 100, 'pids' => 2048,
                'backups' => 10, 'max_servers' => 3, 'allow_custom_port' => true, 'priority_support' => true,
                'allow_rcon' => true, 'is_popular' => true, 'sort' => 3, 'badge' => 'Хит',
                'features' => json_encode([
                    ['icon' => 'server', 'text' => '3 игровых сервера'],
                    ['icon' => 'cpu', 'text' => '6 ГБ RAM, 150% CPU'],
                    ['icon' => 'database', 'text' => '50 ГБ диска'],
                    ['icon' => 'users', 'text' => '60 слотов + докупка'],
                    ['icon' => 'terminal', 'text' => 'RCON и планировщик'],
                    ['icon' => 'headset', 'text' => 'Приоритетная поддержка'],
                ]),
            ],
            [
                'slug' => 'ultra', 'name' => 'Ultra', 'model' => 'hybrid', 'price' => 1490,
                'billing_period' => 'month', 'duration_days' => 30, 'slots' => 150, 'memory_mb' => 12288,
                'cpu_percent' => 300, 'disk_mb' => 102400, 'network_mbps' => 200, 'pids' => 4096,
                'backups' => 20, 'max_servers' => 5, 'allow_custom_port' => true, 'priority_support' => true,
                'allow_rcon' => true, 'is_popular' => false, 'sort' => 4, 'badge' => 'Максимум',
                'features' => json_encode([
                    ['icon' => 'server', 'text' => '5 игровых серверов'],
                    ['icon' => 'cpu', 'text' => '12 ГБ RAM, 300% CPU'],
                    ['icon' => 'database', 'text' => '100 ГБ NVMe'],
                    ['icon' => 'users', 'text' => '150 слотов + докупка'],
                    ['icon' => 'terminal', 'text' => 'RCON, cron, 20 бэкапов'],
                    ['icon' => 'headset', 'text' => 'Персональный менеджер'],
                ]),
            ],
            [
                'slug' => 'per-slot', 'name' => 'По слотам', 'model' => 'slots', 'price' => 0,
                'billing_period' => 'month', 'duration_days' => 30, 'slots' => 0, 'memory_mb' => 0,
                'cpu_percent' => 0, 'disk_mb' => 0, 'network_mbps' => 0, 'pids' => 0,
                'backups' => 3, 'max_servers' => 20, 'is_public' => true, 'sort' => 5,
                'short_description' => 'Платите ровно за слоты',
                'description' => 'Тариф без базовой платы: стоимость считается по слотам выбранной игры.',
            ],
        ];

        foreach ($tariffs as $t) {
            $tariff = Tariff::create($t);

            if ($t['model'] === 'hybrid') {
                $tariff->prices()->createMany([
                    ['resource' => 'extra_slots', 'label' => 'Дополнительный слот', 'unit' => 'шт',
                     'unit_quantity' => 1, 'price' => 12, 'max_quantity' => 1000, 'sort' => 1],
                    ['resource' => 'extra_memory', 'label' => 'Дополнительная память', 'unit' => 'ГБ',
                     'unit_quantity' => 1024, 'price' => 90, 'max_quantity' => 65536, 'sort' => 2],
                    ['resource' => 'extra_disk', 'label' => 'Дополнительный диск', 'unit' => 'ГБ',
                     'unit_quantity' => 10240, 'price' => 60, 'max_quantity' => 1048576, 'sort' => 3],
                    ['resource' => 'extra_cpu', 'label' => 'Дополнительный CPU', 'unit' => '%',
                     'unit_quantity' => 25, 'price' => 45, 'max_quantity' => 800, 'sort' => 4],
                    ['resource' => 'port', 'label' => 'Красивый порт (3000-3099)', 'unit' => 'шт',
                     'unit_quantity' => 1, 'price' => 350, 'is_recurring' => false, 'max_quantity' => 1, 'sort' => 5],
                    ['resource' => 'support', 'label' => 'Приоритетная поддержка', 'unit' => 'мес',
                     'unit_quantity' => 1, 'price' => 199, 'max_quantity' => 12, 'sort' => 6],
                ]);
            }
        }

        // ── Каталог игр ──────────────────────────────────────────────────
        $games = Game::defaults();
        foreach ($games as $g) {
            Game::create($g);
        }

        // Шаблоны плагинов для Minecraft
        $mc = Game::where('slug', 'minecraft-java')->first();
        if ($mc) {
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'paper', 'name' => 'Paper', 'type' => 'build',
                'version' => '1.21', 'source_type' => 'url',
                'source_url' => 'https://api.papermc.io/v2/projects/paper/versions/1.21/builds',
                'is_official' => true, 'sort' => 1,
                'description' => 'Оптимизированный форк Bukkit/Spigot — быстрее оригинала',
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'spigot', 'name' => 'Spigot', 'type' => 'build',
                'version' => '1.20.4', 'source_type' => 'url',
                'source_url' => 'https://hub.spigotmc.org/jenkins/job/Spigot/lastSuccessfulBuild/artifact/target/Spigot.jar',
                'is_official' => true, 'sort' => 2,
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'vanilla', 'name' => 'Vanilla', 'type' => 'build',
                'version' => '1.21', 'source_type' => 'builtin', 'builtin_path' => 'minecraft/vanilla',
                'is_official' => true, 'sort' => 3,
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'essentialsx', 'name' => 'EssentialsX', 'type' => 'plugin',
                'source_type' => 'url',
                'source_url' => 'https://api.modrinth.com/v2/project/essentialsx/version',
                'target_path' => 'plugins/', 'is_official' => true, 'sort' => 10,
                'description' => '/kit, /home, /spawn — базовые команды для сервера',
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'luckperms', 'name' => 'LuckPerms', 'type' => 'plugin',
                'source_type' => 'url', 'target_path' => 'plugins/',
                'source_url' => 'https://api.modrinth.com/v2/project/luckperms/version',
                'is_official' => true, 'sort' => 11,
                'description' => 'Система прав и групп',
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'worldedit', 'name' => 'WorldEdit', 'type' => 'plugin',
                'source_type' => 'url', 'target_path' => 'plugins/',
                'source_url' => 'https://api.modrinth.com/v2/project/worldedit/version',
                'is_official' => true, 'sort' => 12,
            ]);
            GameTemplate::create([
                'game_id' => $mc->id, 'slug' => 'litematica', 'name' => 'Litematica', 'type' => 'mod',
                'source_type' => 'url', 'target_path' => 'mods/',
                'source_url' => 'https://api.modrinth.com/v2/project/litematica/version',
                'is_official' => false, 'sort' => 20,
            ]);
        }

        $cs2 = Game::where('slug', 'cs2')->first();
        if ($cs2) {
            GameTemplate::create([
                'game_id' => $cs2->id, 'slug' => 'sourcemod', 'name' => 'SourceMod', 'type' => 'plugin',
                'source_type' => 'builtin', 'builtin_path' => 'cs2/sourcemod', 'is_official' => true,
                'target_path' => 'addons/sourcemod', 'sort' => 1,
            ]);
            GameTemplate::create([
                'game_id' => $cs2->id, 'slug' => 'metamod', 'name' => 'MetaMod', 'type' => 'plugin',
                'source_type' => 'builtin', 'builtin_path' => 'cs2/metamod', 'is_official' => true,
                'target_path' => 'addons/metamod', 'sort' => 2,
            ]);
        }

        // ── Отделы поддержки ─────────────────────────────────────────────
        foreach ([
            ['slug' => 'billing', 'name' => 'Оплата и баланс', 'email' => 'billing@example.com', 'sort' => 1],
            ['slug' => 'technical', 'name' => 'Технические вопросы', 'email' => 'tech@example.com', 'sort' => 2],
            ['slug' => 'abuse', 'name' => 'Жалобы и блокировки', 'email' => 'abuse@example.com', 'sort' => 3],
            ['slug' => 'other', 'name' => 'Другое', 'email' => null, 'sort' => 9],
        ] as $d) {
            TicketDepartment::create($d);
        }

        // ── Демо-пользователь ────────────────────────────────────────────
        $demo = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Демо',
            'email' => 'demo@example.com',
            'password' => Hash::make('demo12345'),
            'role' => 'user',
            'email_verified_at' => $now,
            'referral_code' => strtoupper(Str::random(8)),
            'balance' => 500,
            'total_deposited' => 500,
            'referred_by' => $admin->id,
            'status' => 'active',
            'register_ip' => '127.0.0.1',
        ]);

        PromoCode::create([
            'code' => 'WELCOME10', 'name' => 'Скидка 10% новичкам', 'type' => 'discount',
            'percent' => 10, 'max_uses' => 1000, 'per_user_limit' => 1,
            'first_payment_only' => true, 'is_active' => true, 'created_by' => $admin->id,
            'valid_until' => now()->addYear(),
        ]);

        PromoCode::create([
            'code' => 'DEMO3DAY', 'name' => '+3 дня к аренде', 'type' => 'duration',
            'days' => 3, 'max_uses' => 500, 'per_user_limit' => 3, 'is_active' => true,
            'created_by' => $admin->id, 'valid_until' => now()->addYear(),
        ]);

        PromoCode::create([
            'code' => 'BONUS500', 'name' => '500 ₽ на баланс', 'type' => 'bonus',
            'bonus_rub' => 500, 'max_uses' => 200, 'per_user_limit' => 1, 'is_active' => true,
            'created_by' => $admin->id, 'valid_until' => now()->addMonths(6),
        ]);

        Referral::create([
            'referrer_id' => $admin->id,
            'referred_id' => $demo->id,
            'status' => 'completed',
            'reward_referrer' => 100,
            'reward_referred' => 100,
            'order_amount' => 500,
            'trigger' => 'first_payment',
            'completed_at' => $now,
        ]);

        // ── Ноды (пустые, админ добавит через админку / CLI) ─────────────
        // Создаём заготовку, чтобы было видно, как выглядит нода в UI.
        Setting::create([
            'group' => 'general',
            'key' => 'install.completed',
            'value' => now()->toIso8601String(),
            'type' => 'string',
            'label' => 'Установка завершена',
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->delete();
        DB::table('referrals')->delete();
        DB::table('promo_codes')->delete();
        DB::table('users')->where('email', 'admin@example.com')->delete();
    }
};
