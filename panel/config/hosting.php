<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ФАЙЛ ВЫБОРА (hosting.php)
|--------------------------------------------------------------------------
| Это главный файл настройки GameDock. Установщик Debian 13 (deploy/install.sh)
| задаёт здесь вопросы и записывает ответы. Админка может переопределить
| любой параметр «на лету» (см. App\Modules\Admin\Services\SettingService) —
| значение из БД имеет приоритет над этим файлом.
|
| Меняйте значения и выполняйте:  php artisan config:clear
*/

return [

    /*
    |------------------------------------------------------------------
    | Режим работы с нодами
    |------------------------------------------------------------------
    | single  — одна нода, выбор ноды скрыт (для теста и VPS «всё сразу»)
    | manual  — администратор вручную выбирает ноду при создании сервера
    | auto    — панель сама распределяет серверы по свободным нодам (балансировка
    |           по свободной RAM/CPU/диску, с учётом региона и лимитов ноды)
    */
    'node_mode' => env('GD_NODE_MODE', 'auto'),

    /*
    |------------------------------------------------------------------
    | Рантайм игровых процессов
    |------------------------------------------------------------------
    | docker — изолированные контейнеры, лимиты через docker update (рекомендуем)
    | podman — то же самое, но на Podman (rootless возможен)
    | lxc    — контейнеры Proxmox VE (команда pct)
    | native — нативные процессы под systemd + изоляция через systemd-run/cgroups v2
    |
    | Доступные рантаймы перечислены в runtime.available. Админка показывает
    | только те, что реально есть на конкретной ноде (нода сообщает через
    | heartbeat, какие драйверы доступны).
    */
    'runtime' => [
        'default' => env('GD_RUNTIME', 'docker'),

        'available' => [
            'docker' => [
                'label' => 'Docker',
                'socket' => env('GD_DOCKER_SOCKET', '/var/run/docker.sock'),
                'network_prefix' => 'gamedock',
                // Шаблон контейнера. {name} {image} {user} {working_dir} {mount_root}
                'default_image' => env('GD_DOCKER_DEFAULT_IMAGE', 'debian:12-slim'),
                'cgroup_version' => 2,
            ],
            'podman' => [
                'label' => 'Podman',
                'socket' => env('GD_PODMAN_SOCKET', 'unix:///run/podman/podman.sock'),
                'network_prefix' => 'gamedock',
                'default_image' => env('GD_PODMAN_DEFAULT_IMAGE', 'debian:12-slim'),
                'rootless' => (bool) env('GD_PODMAN_ROOTLESS', false),
            ],
            'lxc' => [
                'label' => 'Proxmox LXC',
                'pve_host' => env('GD_PVE_HOST', '127.0.0.1'),
                'pve_user' => env('GD_PVE_USER', 'root@pam'),
                'template' => env('GD_PVE_TEMPLATE', 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst'),
                'storage' => env('GD_PVE_STORAGE', 'local-lvm'),
                'bridge' => env('GD_PVE_BRIDGE', 'vmbr0'),
            ],
            'native' => [
                'label' => 'Native (systemd + cgroups v2)',
                'systemd_run_scope' => 'gamedock.slice',
                'cgroup_root' => '/sys/fs/cgroup/gamedock',
                'memory_swap_limit' => 0, // 0 = не ограничивать подкачку
            ],
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Каталоги на ноде
    |------------------------------------------------------------------
    */
    'paths' => [
        // Корень, где лежат каталоги всех игровых серверов
        'servers_root' => env('GD_SERVERS_ROOT', '/home/gamedock/servers'),
        // Хранилище бэкапов на ноде (потом реплицируется в S3, если включено)
        'backups_root' => env('GD_BACKUPS_ROOT', '/home/gamedock/backups'),
        // Директория с шаблонами установки игр (репозиторий game-images)
        'templates_root' => env('GD_TEMPLATES_ROOT', '/opt/gamedock/game-images'),
        // Каталог для скачанных/загруженных пользователем плагинов
        'plugins_root' => env('GD_PLUGINS_ROOT', '/home/gamedock/plugins'),
        'user' => env('GD_SYSTEM_USER', 'gamedock'),
        'group' => env('GD_SYSTEM_GROUP', 'gamedock'),
    ],

    /*
    |------------------------------------------------------------------
    | Протокол связи панель <-> агент
    |------------------------------------------------------------------
    */
    'agent' => [
        // Внешний адрес агента (панель подключается к нему по WSS).
        // Оставьте пустым, если агенты сами подключаются к панели (входящий режим).
        'public_url' => env('GD_AGENT_URL', ''),
        'inbound' => (bool) env('GD_AGENT_INBOUND', true), // агенты → панель: wss://panel/agent/ws
        'connect_timeout' => 10,
        'request_timeout' => 30,
        'heartbeat_interval' => 5,   // секунд, панель ждёт heartbeat
        'offline_after' => 25,       // секунд без heartbeat → нода считается офлайн
        'rpc_timeout' => 15,
        // Максимум одновременных RPC на одну ноду
        'max_parallel_rpc' => 8,
    ],

    /*
    |------------------------------------------------------------------
    | Порты
    |------------------------------------------------------------------
    */
    'ports' => [
        // Из этого диапазона выдаются игровые, query и rcon порты
        'range' => [
            'game' => [25000, 25999],
            'query' => [26000, 26999],
            'rcon' => [27000, 27199],
        ],
        // Разрешить покупать «красивый» порт (цена в настройках тарифа)
        'allow_purchase' => true,
        'allow_purchase_prices' => [
            'low' => ['from' => 3000, 'to' => 3049, 'price' => 150.00],   // 3000-3049
            'mid' => ['from' => 3050, 'to' => 3099, 'price' => 350.00],   // 3050-3099
            'high' => ['from' => 25565, 'to' => 25575, 'price' => 500.00], // 25565+
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Ресурсы и значения по умолчанию
    |------------------------------------------------------------------
    */
    'resources' => [
        'defaults' => [
            'memory_mb' => 1024,
            'cpu_percent' => 50,       // % от одного ядра
            'swap_mb' => 0,
            'disk_mb' => 10240,
            'network_mbps' => 25,
            'pids' => 512,
        ],
        'limits' => [
            'memory_mb' => ['min' => 512, 'max' => 65536],
            'cpu_percent' => ['min' => 10, 'max' => 800],
            'disk_mb' => ['min' => 2048, 'max' => 1048576],
            'network_mbps' => ['min' => 5, 'max' => 1000],
            'pids' => ['min' => 64, 'max' => 8192],
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Маркетинговые механики
    |------------------------------------------------------------------
    | Установщик спрашивает, что включить, и переключает эти флаги.
    | Админка может включать/выключать их в разделе «Маркетинг».
    */
    'marketing' => [
        // Промокоды: скидка на оплату
        'promo_discount' => ['enabled' => true, 'max_percent' => 90],
        // Промокоды: добавить дни к аренде
        'promo_duration' => ['enabled' => true, 'max_days' => 365],
        // Промокоды: разовый бонус (слоты / RAM / баланс)
        'promo_bonus' => ['enabled' => true, 'max_bonus_rub' => 1000.0],

        // Реферальная программа
        'referral' => [
            'enabled' => true,
            'reward_referrer_rub' => 100.0,     // пригласившему
            'reward_referred_rub' => 100.0,     // новичку
            'reward_after_payment' => true,     // начислять после первой оплаты
            'min_payment' => 100.0,             // минимальная сумма оплаты для начисления
        ],

        // Секретные коды для игроков (игрок вводит в чате — начисляется бонус)
        'secret_codes' => [
            'enabled' => true,
            'allow_in_game_chat' => true,   // перехват команд в консоли сервера
            'allow_personal_codes' => true, // каждый игрок может завести свои коды
            'prefixes' => ['//', '!', '/promo'],
        ],

        // Тестовый период при регистрации
        'trial' => [
            'enabled' => true,
            'days' => 3,
            'tariff' => 'trial',   // id тарифа-триала, создаётся сидером
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Биллинг
    |------------------------------------------------------------------
    */
    'billing' => [
        'currency' => 'RUB',
        'currency_symbol' => '₽',
        'round_decimals' => 2,

        // Ежедневное списание за сервер (модель «по факту») либо фикс за период
        'default_model' => 'slots', // slots | package | hybrid

        'grace_period_days' => 3,       // сколько дней ждать пополнения баланса
        'warn_before_expiry_days' => 3, // за сколько дней предупредить
        'stop_on_zero_balance' => true, // авто-остановка при нулевом балансе
        'delete_after_stop_days' => 14,  // через сколько дней удалить остановленные данные
        'charge_interval' => 'daily',   // daily | hourly | monthly
        'charge_hour' => 3,             // час списания (по таймзоне панели)
        'timezone' => 'Europe/Moscow',

        // Минимальная сумма пополнения кошелька
        'min_deposit' => 100.0,
        'min_refund' => 1.0,
    ],

    /*
    |------------------------------------------------------------------
    | Регистрация и безопасность
    |------------------------------------------------------------------
    */
    'auth' => [
        'registration_enabled' => true,
        'invite_only' => false,
        'require_email_verification' => true,
        'verify_email_with' => 'link', // link | code
        'terms_acceptance' => true,
        'recaptcha' => [
            'enabled' => true,
            'provider' => 'turnstile',   // turnstile | recaptcha_v2 | recaptcha_v3
            'site_key' => env('GD_TURNSTILE_SITE_KEY', ''),
            'secret_key' => env('GD_TURNSTILE_SECRET_KEY', ''),
        ],
        'oauth' => [
            'google' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
            'vk' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
            'telegram' => ['enabled' => false, 'bot_token' => ''],
        ],
        'password' => [
            'min' => 8,
            'require_mixed_case' => true,
            'require_numbers' => true,
            'require_symbols' => false,
        ],
        'two_factor' => [
            'enabled' => true,
            'required_for_roles' => ['superadmin', 'admin'],
            'grace_days' => 7, // сколько дней на настройку 2FA после входа
        ],
        'bruteforce' => [
            'max_attempts' => 5,
            'decay_minutes' => 15,
            'lockout_minutes' => 15,
        ],
        'session' => [
            'lifetime_minutes' => 10080, // 7 дней
            'idle_timeout_minutes' => 60,
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Мониторинг и оповещения
    |------------------------------------------------------------------
    */
    'monitoring' => [
        'interval' => 5,             // секунд между точками метрик
        'raw_ttl' => 604800,         // 7 дней в Redis
        'aggregate_interval' => 3600,
        'aggregate_ttl' => 2592000,  // 30 дней в MySQL
        'public' => [
            'enabled' => true,
            'show_names' => true,
            'show_address' => true,
            'show_player_count' => true,
            'min_uptime_for_public' => 3600,
        ],
        'alerts' => [
            'server_down' => true,
            'server_down_after_seconds' => 120,
            'high_ram' => true,
            'high_ram_threshold' => 90,   // %
            'high_cpu' => true,
            'high_cpu_threshold' => 95,   // %
            'disk_low' => true,
            'disk_low_threshold' => 90,   // %
            'node_offline' => true,
            'balance_low' => true,
            'balance_low_threshold' => 50.0,
            'rate_limit_per_hour' => 10,
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Бэкапы
    |------------------------------------------------------------------
    */
    'backups' => [
        'default_schedule' => 'daily',   // hourly | daily | weekly | manual
        'keep_daily' => 7,
        'keep_weekly' => 4,
        'keep_monthly' => 3,
        'max_size_mb' => 10240,
        'upload_to_s3' => true,
        's3_bucket' => env('GD_S3_BUCKET', ''),
        'compress' => 'tar.gz',          // none | tar.gz | zip
        'exclude' => ['cache', 'logs', '*.tmp', 'crashreports', '*.log'],
    ],

    /*
    |------------------------------------------------------------------
    | Логи игровых серверов
    |------------------------------------------------------------------
    */
    'logs' => [
        'max_size_mb' => 128,
        'keep_files' => 5,
        'keep_days' => 30,
        'realtime' => true,
        'realtime_buffer' => 2000,
        'search' => ['enabled' => true, 'max_results' => 500],
    ],

    /*
    |------------------------------------------------------------------
    | Саб-аккаунты (дополнительные пользователи сервера)
    |------------------------------------------------------------------
    */
    'sub_accounts' => [
        'enabled' => true,
        'per_server_mode' => true,  // true — можно включать/выключать для каждого сервера
        'max_per_server' => 10,
        'permissions' => [
            'console.read', 'console.write', 'console.control',
            'files.read', 'files.write', 'files.delete',
            'backup.create', 'backup.restore', 'settings.read', 'settings.write',
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Планировщик (cron-задачи игровых серверов и системные задачи)
    |------------------------------------------------------------------
    */
    'scheduler' => [
        'enabled' => true,
        'max_per_server' => 20,
        'job_types' => [
            'command'    => ['label' => 'Команда в консоль', 'cron_able' => true],
            'restart'    => ['label' => 'Рестарт', 'cron_able' => true],
            'start'      => ['label' => 'Запуск', 'cron_able' => true],
            'stop'       => ['label' => 'Остановка', 'cron_able' => true],
            'backup'     => ['label' => 'Бэкап', 'cron_able' => true],
            'update'     => ['label' => 'Обновление игры', 'cron_able' => true],
            'webhook'    => ['label' => 'HTTP-запрос', 'cron_able' => true],
        ],
        'default_cron' => '0 */6 * * *',
    ],

    /*
    |------------------------------------------------------------------
    | Watchdog (авто-рестарт при падении)
    |------------------------------------------------------------------
    */
    'watchdog' => [
        'enabled' => true,
        'restart_delay' => 10,
        'max_restarts' => 5,
        'window_minutes' => 15,
        'cooldown_on_limit' => 3600,
    ],

    /*
    |------------------------------------------------------------------
    | Магазин доп. услуг
    |------------------------------------------------------------------
    */
    'store' => [
        'enabled' => true,
        'products' => [
            'extra_slots'   => ['enabled' => true, 'label' => 'Доп. слоты',      'unit' => 'шт'],
            'extra_memory'  => ['enabled' => true, 'label' => 'Доп. RAM',         'unit' => 'ГБ'],
            'extra_storage' => ['enabled' => true, 'label' => 'Доп. диск',        'unit' => 'ГБ'],
            'extra_cpu'     => ['enabled' => true, 'label' => 'Доп. CPU',         'unit' => '%'],
            'port'          => ['enabled' => true, 'label' => 'Выбор порта',      'unit' => 'шт'],
            'backup_slots'  => ['enabled' => true, 'label' => 'Доп. бэкапы',      'unit' => 'шт'],
            'support'       => ['enabled' => true, 'label' => 'Приоритетная поддержка', 'unit' => 'мес'],
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Ограничения аккаунта
    |------------------------------------------------------------------
    */
    'account' => [
        'max_servers_per_user' => 20,
        'max_servers_per_user_by_tariff' => true,
        'allow_delete_with_active' => false,
        'refund_on_delete_days' => 0, // дней для возврата остатка
    ],

    /*
    |------------------------------------------------------------------
    | Локализация
    |------------------------------------------------------------------
    */
    'locale' => [
        'default' => 'ru',
        'available' => ['ru' => 'Русский', 'en' => 'English'],
        'switcher_in_header' => true,
        'timezone' => 'Europe/Moscow',
        'date_format' => 'd.m.Y',
        'datetime_format' => 'd.m.Y H:i',
    ],

    /*
    |------------------------------------------------------------------
    | Темы и брендинг
    |------------------------------------------------------------------
    */
    'branding' => [
        'name' => env('GD_BRAND_NAME', 'GameDock'),
        'legal_name' => env('GD_BRAND_LEGAL', 'GameDock LLC'),
        'logo' => '/assets/img/logo.svg',
        'favicon' => '/favicon.ico',
        'support_email' => env('GD_SUPPORT_EMAIL', 'support@example.com'),
        'support_chat_url' => env('GD_SUPPORT_CHAT_URL', ''),
        'docs_url' => env('GD_DOCS_URL', ''),
        'theme' => 'dark',
        'allow_theme_switch' => true,
        'accent_colors' => [
            'primary' => '#6366f1',
            'secondary' => '#0ea5e9',
            'success' => '#22c55e',
            'warning' => '#f59e0b',
            'danger' => '#ef4444',
        ],
        'footer_text' => '',
        'icp' => '',
    ],

    /*
    |------------------------------------------------------------------
    | Платежи — какие включить
    |------------------------------------------------------------------
    | Установщик спрашивает про платежи и заполняет ключи в .env,
    | здесь — только флаг включения и лимиты.
    */
    'payments' => [
        'enabled' => true,
        'methods' => [
            'wallet' => ['enabled' => true, 'label' => 'Баланс в панели'],
            'yookassa' => [
                'enabled' => true,
                'label' => 'ЮKassa (карты, СБП, ЮMoney)',
                'shop_id' => env('GD_YOOKASSA_SHOP_ID', ''),
                'secret_key' => env('GD_YOOKASSA_SECRET_KEY', ''),
                'success_url' => env('GD_YOOKASSA_SUCCESS_URL', ''),
                'idempotence' => true,
            ],
            'tinkoff' => [
                'enabled' => false,
                'label' => 'Т-Банк Интернет-магазин',
                'terminal_key' => env('GD_TINKOFF_TERMINAL_KEY', ''),
                'password' => env('GD_TINKOFF_PASSWORD', ''),
            ],
            'cryptobot' => [
                'enabled' => true,
                'label' => 'Криптобот (Telegram)',
                'token' => env('GD_CRYPTOBOT_TOKEN', ''),
                'crypto' => ['TON', 'USDT', 'BTC', 'ETH'],
                'expire_minutes' => 30,
            ],
            'manual' => [
                'enabled' => true,
                'label' => 'Ручной приём (карта/перевод)',
                'notify_email' => env('GD_SUPPORT_EMAIL', 'support@example.com'),
            ],
        ],
        'min_amount' => 50.0,
        'auto_topup' => [
            'enabled' => true,
            'when_below' => 50.0,   // пополнять, если баланс ниже
            'min_amount' => 200.0,
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Поддержка
    |------------------------------------------------------------------
    */
    'support' => [
        'tickets' => [
            'enabled' => true,
            'max_open_per_user' => 5,
            'auto_close_days' => 14,
            'priority_levels' => ['low', 'normal', 'high', 'urgent'],
        ],
        'knowledge_base' => ['enabled' => true, 'url' => ''],
    ],

    /*
    |------------------------------------------------------------------
    | Обновления панели
    |------------------------------------------------------------------
    */
    'updates' => [
        'check' => true,
        'channel' => 'stable',   // stable | beta
        'url' => env('GD_UPDATE_URL', ''),
        'webhook' => env('GD_UPDATE_WEBHOOK', ''),
    ],
];
