<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Каталог игр, заливаемый сидером.
 *
 * Всё содержимое редактируется в админке (раздел «Игры») — этот файл лишь
 * «стартовый набор», чтобы после установки сразу было что показать.
 * Любую игру отсюда можно скопировать и превратить в свою (is_custom = 1).
 */
final class GameCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::minecraftJava(),
            self::minecraftBedrock(),
            self::cs2(),
            self::csgo(),
            self::samp(),
            self::crmp(),
            self::ragemp(),
            self::altv(),
            self::mta(),
            self::rust(),
            self::unturned(),
            self::ark(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Minecraft Java
    // ─────────────────────────────────────────────────────────────────────

    private static function minecraftJava(): array
    {
        return self::game('minecraft-java', 'Minecraft (Java)', 'minecraft', [
            'short_description' => 'Классический сервер на Paper / Spigot / Vanilla',
            'description' => 'Полноценный Minecraft на Java-версии. На старте доступны сборки Paper, Spigot и Vanilla — переключаются в панели, установка занимает пару минут. Ставьте плагины и моды в один клик, управляйте через RCON и планировщик задач.',
            'icon' => 'minecraft',
            'uses_steamcmd' => false,
            'image' => 'eclipse-temurin:21-jre',
            'supports_plugins' => true,
            'supports_custom_builds' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 1,
            'max_slots' => 500,
            'default_slots' => 20,
            'price_per_slot_month' => 8,
            'default_memory_mb' => 2048,
            'min_memory_mb' => 1024,
            'default_cpu_percent' => 60,
            'default_disk_mb' => 15360,

            'startup' => [
                'exec' => 'java',
                'args' => [
                    '-Xms{ram_mb}M',
                    '-Xmx{ram_mb}M',
                    '-XX:+UseG1GC',
                    '-XX:MaxGCPauseMillis=50',
                    '-XX:+UnlockExperimentalVMOptions',
                    '-XX:+DisableExplicitGC',
                    '-XX:G1NewSizePercent=30',
                    '-XX:MaxTenuringThreshold=1',
                    '-Dcom.mojang.eula.agree=true',
                    '-jar',
                    'server.jar',
                    'nogui',
                ],
                'cwd' => '.',
                'env' => ['MINECRAFT_MEMORY' => '{ram_mb}M'],
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 60,
                'rcon' => ['type' => 'minecraft', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'minecraft', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'minecraft/install.sh',
                'build' => 'paper',
                'version' => '1.21',
                'timeout' => 900,
                'commands' => [],
            ],

            'builds' => [
                ['id' => 'paper', 'name' => 'Paper', 'version' => '1.21',
                    'installer' => ['type' => 'script', 'script' => 'minecraft/install-paper.sh', 'timeout' => 900]],
                ['id' => 'spigot', 'name' => 'Spigot', 'version' => '1.20.4',
                    'installer' => ['type' => 'script', 'script' => 'minecraft/install-spigot.sh', 'timeout' => 900]],
                ['id' => 'vanilla', 'name' => 'Vanilla', 'version' => '1.21',
                    'installer' => ['type' => 'script', 'script' => 'minecraft/install-vanilla.sh', 'timeout' => 1200]],
                ['id' => 'folia', 'name' => 'Folia', 'version' => '1.21',
                    'installer' => ['type' => 'script', 'script' => 'minecraft/install-folia.sh', 'timeout' => 900]],
                ['id' => 'purpur', 'name' => 'Purpur', 'version' => '1.21',
                    'installer' => ['type' => 'script', 'script' => 'minecraft/install-purpur.sh', 'timeout' => 900]],
            ],

            'config_files' => [
                [
                    'path' => 'server.properties',
                    'format' => 'properties',
                    'label' => 'server.properties',
                    'fields' => [
                        ['key' => 'motd', 'type' => 'text', 'label' => 'MOTD', 'maxlength' => 120],
                        ['key' => 'max-players', 'type' => 'number', 'label' => 'Максимум игроков', 'min' => 1, 'max' => 500],
                        ['key' => 'level-name', 'type' => 'text', 'label' => 'Название мира'],
                        ['key' => 'level-seed', 'type' => 'text', 'label' => 'Сид мира'],
                        ['key' => 'level-type', 'type' => 'select', 'label' => 'Тип мира',
                            'options' => ['default', 'flat', 'largeBiomes', 'amplified', 'normal', 'buffered']],
                        ['key' => 'gamemode', 'type' => 'select', 'label' => 'Режим игры',
                            'options' => ['survival', 'creative', 'adventure', 'spectator']],
                        ['key' => 'difficulty', 'type' => 'select', 'label' => 'Сложность',
                            'options' => ['peaceful', 'easy', 'normal', 'hard', 'hardest']],
                        ['key' => 'view-distance', 'type' => 'number', 'label' => 'Дальность прорисовки', 'min' => 3, 'max' => 32],
                        ['key' => 'simulation-distance', 'type' => 'number', 'label' => 'Дальность симуляции', 'min' => 3, 'max' => 32],
                        ['key' => 'online-mode', 'type' => 'bool', 'label' => 'Пиратка (offline mode)'],
                        ['key' => 'white-list', 'type' => 'bool', 'label' => 'Белый список'],
                        ['key' => 'spawn-protection', 'type' => 'number', 'label' => 'Защита спавна', 'min' => 0, 'max' => 100],
                        ['key' => 'enable-command-block', 'type' => 'bool', 'label' => 'Командный блок'],
                        ['key' => 'pvp', 'type' => 'bool', 'label' => 'PvP'],
                        ['key' => 'allow-flight', 'type' => 'bool', 'label' => 'Полёт'],
                        ['key' => 'max-players', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 500],
                        ['key' => 'port', 'type' => 'port', 'label' => 'Порт игры', 'read_only' => true],
                        ['key' => 'rcon.port', 'type' => 'port', 'label' => 'Порт RCON', 'read_only' => true],
                        ['key' => 'enable-rcon', 'type' => 'bool', 'label' => 'Включить RCON', 'default' => true],
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Minecraft Bedrock
    // ─────────────────────────────────────────────────────────────────────

    private static function minecraftBedrock(): array
    {
        return self::game('minecraft-bedrock', 'Minecraft (Bedrock)', 'minecraft', [
            'short_description' => 'Сервер для консолей, телефона и Windows',
            'description' => 'PocketMine-MP — сервер Bedrock Edition. Играют с Xbox, PS, Switch, Android, iOS и Windows. Есть свой античит и система банов, совместимость с плагинами PocketMine.',
            'icon' => 'minecraft',
            'image' => 'eclipse-temurin:21-jre',
            'supports_plugins' => true,
            'supports_rcon' => false,
            'supports_query' => true,
            'min_slots' => 1,
            'max_slots' => 200,
            'default_slots' => 20,
            'price_per_slot_month' => 8,
            'default_memory_mb' => 1536,
            'min_memory_mb' => 1024,
            'default_disk_mb' => 10240,

            'startup' => [
                'exec' => 'php',
                'args' => [
                    'src/pocketmine/PocketMine.php',
                    '--enable-ansi',
                    '--no-wizard',
                    '--disable-ansi',
                ],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 45,
                'rcon' => null,
                'query' => ['type' => 'bedrock', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'minecraft-bedrock/install.sh',
                'timeout' => 900,
            ],

            'config_files' => [
                [
                    'path' => 'pocketmine.yml',
                    'format' => 'yaml',
                    'label' => 'PocketMine.yml',
                    'fields' => [
                        ['key' => 'settings.language', 'type' => 'text', 'label' => 'Язык'],
                        ['key' => 'settings.shutdown-message', 'type' => 'text', 'label' => 'Сообщение при выключении'],
                        ['key' => 'network.max-players', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 200],
                        ['key' => 'network.bind-address', 'type' => 'port', 'label' => 'Порт', 'read_only' => true],
                        ['key' => 'network.enable-query', 'type' => 'bool', 'label' => 'Включить query'],
                    ],
                ],
                [
                    'path' => 'server.properties',
                    'format' => 'properties',
                    'label' => 'server.properties',
                    'fields' => [
                        ['key' => 'motd', 'type' => 'text', 'label' => 'MOTD'],
                        ['key' => 'gamemode', 'type' => 'select', 'label' => 'Режим',
                            'options' => ['survival', 'creative', 'adventure', 'spectator']],
                        ['key' => 'difficulty', 'type' => 'select', 'label' => 'Сложность',
                            'options' => ['peaceful', 'easy', 'normal', 'hard']],
                        ['key' => 'level-name', 'type' => 'text', 'label' => 'Мир'],
                        ['key' => 'white-list', 'type' => 'bool', 'label' => 'Белый список'],
                        ['key' => 'pvp', 'type' => 'bool', 'label' => 'PvP'],
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CS2 / CS:GO
    // ─────────────────────────────────────────────────────────────────────

    private static function cs2(): array
    {
        return self::game('cs2', 'Counter-Strike 2', 'cs', [
            'short_description' => 'Valve SourceMod, Metamod, боты',
            'description' => 'Выделенный сервер CS2. Ставится через SteamCMD, поддерживает SourceMod и MetaMod, автоматический рестарт, RCON и планировщик. Управление картами и конфигом прямо из панели.',
            'icon' => 'cs',
            'uses_steamcmd' => true,
            'steam_appid' => 730,
            'image' => 'steamcmd/steamcmd:latest',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 2,
            'max_slots' => 64,
            'default_slots' => 24,
            'price_per_slot_month' => 10,
            'default_memory_mb' => 2048,
            'min_memory_mb' => 1024,
            'default_disk_mb' => 30720,

            'startup' => [
                'exec' => './game/bin/linuxsteamrt64/cs2',
                'args' => [
                    '-dedicated',
                    '+ip', '0.0.0.0',
                    '+port', '{game_port}',
                    '+queryport', '{query_port}',
                    '+rcon_port', '{rcon_port}',
                    '+maxplayers', '{slots}',
                    '+map', '{map}',
                    '-authkey', '{steam_query_key}',
                    '+game_mode', '{game_mode}',
                    '+mp_autoteambalance', '1',
                    '+fps_max', '128',
                    '+sv_lan', '0',
                    '+fps_max', '0',
                ],
                'cwd' => '.',
                'env' => ['LD_LIBRARY_PATH' => './linux64'],
                'user' => 'gamedock',
                'stop_signal' => 'SIGINT',
                'stop_timeout' => 30,
                'rcon' => ['type' => 'source', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'valve', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'steamcmd',
                'app_id' => 730,
                'anonymous' => true,
                'script' => 'steamcmd/install-app.sh',
                'post_install' => 'cs2/post-install.sh',
                'timeout' => 3600,
            ],

            'bootstrap_files' => [
                ['path' => 'game/csgo/cfg/autoexec.cfg', 'content' => "// Создаётся панелью GameDock\n// Управляйте сервером из раздела «Настройки»\nexec gamemode_competitive\n"],
            ],

            'config_files' => [
                [
                    'path' => 'game/csgo/cfg/autoexec.cfg',
                    'format' => 'cfg',
                    'label' => 'autoexec.cfg',
                    'fields' => [
                        ['key' => 'hostname', 'type' => 'text', 'label' => 'Название сервера'],
                        ['key' => 'map', 'type' => 'text', 'label' => 'Карта', 'default' => 'de_dust2'],
                        ['key' => 'sv_password', 'type' => 'text', 'label' => 'Пароль на вход'],
                        ['key' => 'mp_friendlyfire', 'type' => 'bool', 'label' => 'Дружественный огонь'],
                        ['key' => 'sv_cheats', 'type' => 'bool', 'label' => 'Читы'],
                        ['key' => 'mp_timelimit', 'type' => 'number', 'label' => 'Лимит раунда, мин'],
                        ['key' => 'mp_maxrounds', 'type' => 'number', 'label' => 'Раундов до смены карты'],
                        ['key' => 'bot_quota', 'type' => 'number', 'label' => 'Количество ботов', 'min' => 0, 'max' => 30],
                        ['key' => 'bot_quota_mode', 'type' => 'select', 'label' => 'Режим ботов',
                            'options' => ['fill', 'match', 'normal']],
                    ],
                ],
            ],
        ]);
    }

    private static function csgo(): array
    {
        $def = self::cs2();
        $def['slug'] = 'csgo';
        $def['name'] = 'CS:GO (legacy)';
        $def['short_description'] = 'Классический Source-сервер';
        $def['description'] = 'Устаревший Source-движок CS:GO для проектов на HL2DM/GOTV. Тот же интерфейс управления: плагины, RCON, карты.';
        $def['uses_steamcmd'] = true;
        $def['steam_appid'] = 730;
        $def['startup']['exec'] = './srcds_run';
        $def['startup']['args'] = [
            '-game', 'cstrike',
            '-dedicated', '1',
            '-port', '{game_port}',
            '-queryport', '{query_port}',
            '-maxplayers', '{slots}',
            '+map', '{map}',
            '-console',
        ];
        $def['installer'] = [
            'type' => 'steamcmd',
            'app_id' => 730,
            'anonymous' => true,
            'script' => 'steamcmd/install-app.sh',
            'post_install' => 'csgo/post-install.sh',
            'timeout' => 3600,
        ];
        $def['bootstrap_files'] = [];
        $def['config_files'] = [[
            'path' => 'csgo/cfg/server.cfg',
            'format' => 'cfg',
            'label' => 'server.cfg',
            'fields' => [
                ['key' => 'hostname', 'type' => 'text', 'label' => 'Название'],
                ['key' => 'map', 'type' => 'text', 'label' => 'Карта'],
                ['key' => 'sv_password', 'type' => 'text', 'label' => 'Пароль'],
                ['key' => 'mp_friendlyfire', 'type' => 'bool', 'label' => 'Дружественный огонь'],
            ],
        ]];

        return self::game($def['slug'], $def['name'], $def['family'], $def);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GTA
    // ─────────────────────────────────────────────────────────────────────

    private static function samp(): array
    {
        return self::game('samp', 'GTA SAMP', 'gta', [
            'short_description' => 'SA-MP 0.3.7 / 0.3.DL, плагины Pawn',
            'description' => 'SA-MP — самая популярная мультиплеерная игра на GTA San Andreas. Свои плагины, filterscripts, игровые моды. Установка собранного клиента — через панель, поддержка режимов open/closed и автобана.',
            'icon' => 'gta',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 10,
            'max_slots' => 1000,
            'default_slots' => 50,
            'price_per_slot_month' => 3,
            'default_memory_mb' => 1024,
            'min_memory_mb' => 512,
            'default_disk_mb' => 5120,

            'startup' => [
                'exec' => './samp03svr{build}',
                'args' => [],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 20,
                'rcon' => ['type' => 'samp', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'samp', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'gta/samp/install.sh',
                'timeout' => 600,
            ],

            'config_files' => [
                [
                    'path' => 'server.cfg',
                    'format' => 'samp_cfg',
                    'label' => 'server.cfg',
                    'fields' => [
                        ['key' => 'gamemode0', 'type' => 'text', 'label' => 'Режим 0', 'default' => 'grandlarc 1'],
                        ['key' => 'gamemode1', 'type' => 'text', 'label' => 'Режим 1'],
                        ['key' => 'gamemode2', 'type' => 'text', 'label' => 'Режим 2'],
                        ['key' => 'filterscripts', 'type' => 'text', 'label' => 'Filterscripts'],
                        ['key' => 'maxnpc', 'type' => 'number', 'label' => 'Макс. NPC'],
                        ['key' => 'language', 'type' => 'select', 'label' => 'Язык',
                            'options' => ['0', '1', '2']],
                        ['key' => 'weburl', 'type' => 'text', 'label' => 'Сайт'],
                        ['key' => 'onfootrate', 'type' => 'number', 'label' => 'Скорость бега'],
                        ['key' => 'incarate', 'type' => 'number', 'label' => 'Скорость в машине'],
                        ['key' => 'port', 'type' => 'port', 'label' => 'Порт игры', 'read_only' => true],
                        ['key' => 'rcon_port', 'type' => 'port', 'label' => 'RCON', 'read_only' => true],
                        ['key' => 'maxplayers', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 1000],
                        ['key' => 'hostname', 'type' => 'text', 'label' => 'Название сервера'],
                        ['key' => 'chatlogging', 'type' => 'bool', 'label' => 'Логирование чата'],
                        ['key' => 'query_enable', 'type' => 'bool', 'label' => 'Включить query'],
                    ],
                ],
            ],
        ]);
    }

    private static function crmp(): array
    {
        return self::game('crmp', 'GTA CRMP', 'gta', [
            'short_description' => 'Crime City RP — ролевой мод в GTA',
            'description' => 'Crime City Role Play: заранее собранный мод с автоустановкой, конфиг в config.json, плагины в папку cfxmods. Управление слотами, деньгами организаций, депозитами игроков и разными версиями клиента.',
            'icon' => 'gta',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 50,
            'max_slots' => 500,
            'default_slots' => 100,
            'price_per_slot_month' => 4,
            'default_memory_mb' => 6144,
            'min_memory_mb' => 2048,
            'default_disk_mb' => 20480,

            'startup' => [
                'exec' => './server',
                'args' => ['{build}', '{game_port}'],
                'cwd' => '.',
                'env' => ['LAUNCHER_CFGMETHOD' => 'env'],
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 45,
                'rcon' => ['type' => 'source', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'samp', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 45],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'gta/crmp/install.sh',
                'timeout' => 1200,
            ],

            'builds' => [
                ['id' => 'cowa', 'name' => 'Cowa v1', 'installer' => ['type' => 'script', 'script' => 'gta/crmp/install-cowa.sh', 'timeout' => 1200]],
                ['id' => 'optim', 'name' => 'Optim', 'installer' => ['type' => 'script', 'script' => 'gta/crmp/install-optim.sh', 'timeout' => 1200]],
                ['id' => 'aurora', 'name' => 'Aurora', 'installer' => ['type' => 'script', 'script' => 'gta/crmp/install-aurora.sh', 'timeout' => 1200]],
            ],

            'config_files' => [
                [
                    'path' => 'config.json',
                    'format' => 'json',
                    'label' => 'config.json',
                    'fields' => [
                        ['key' => 'bindaddr', 'type' => 'text', 'label' => 'Адрес', 'read_only' => true],
                        ['key' => 'rconport', 'type' => 'port', 'label' => 'RCON', 'read_only' => true],
                        ['key' => 'maxplayers', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 500],
                        ['key' => 'name', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'gamemode', 'type' => 'text', 'label' => 'Режим'],
                        ['key' => 'chatlog', 'type' => 'bool', 'label' => 'Лог чата'],
                        ['key' => 'maxdeposit', 'type' => 'number', 'label' => 'Макс. депозит'],
                        ['key' => 'startercash', 'type' => 'number', 'label' => 'Стартовые деньги'],
                        ['key' => 'closed', 'type' => 'bool', 'label' => 'Закрытый сервер'],
                    ],
                ],
            ],
        ]);
    }

    private static function ragemp(): array
    {
        return self::game('ragemp', 'GTA5 RAGE.MP', 'gta', [
            'short_description' => 'RAGE.MP — GTA V в браузере и на ПК',
            'description' => 'RAGE.MP: серверная часть с C# и клиентская часть с JS. Папки packages/ (серверные скрипты) и client_packages/ (клиентские), поддержка C# плагинов, RCON и собственный редактор файлов.',
            'icon' => 'gta',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 50,
            'max_slots' => 512,
            'default_slots' => 100,
            'price_per_slot_month' => 5,
            'default_memory_mb' => 6144,
            'min_memory_mb' => 3072,
            'default_disk_mb' => 20480,

            'startup' => [
                'exec' => './server',
                'args' => ['{game_port}'],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 45,
                'rcon' => ['type' => 'ragemp', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'ragemp', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 45],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'gta/ragemp/install.sh',
                'timeout' => 1200,
            ],

            'config_files' => [
                [
                    'path' => 'config.json',
                    'format' => 'json',
                    'label' => 'config.json',
                    'fields' => [
                        ['key' => 'port', 'type' => 'port', 'label' => 'Порт', 'read_only' => true],
                        ['key' => 'maxclients', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 512],
                        ['key' => 'name', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'gamemode', 'type' => 'text', 'label' => 'Режим'],
                        ['key' => 'rcon_password', 'type' => 'password', 'label' => 'Пароль RCON'],
                        ['key' => 'csharp', 'type' => 'bool', 'label' => 'C# плагины'],
                        ['key' => 'url', 'type' => 'text', 'label' => 'URL сайта'],
                    ],
                ],
            ],
        ]);
    }

    private static function altv(): array
    {
        return self::game('altv', 'ALT-V (ALTV)', 'gta', [
            'short_description' => 'Мультиплеер для GTA V на движке ALT:V',
            'description' => 'ALT:V — современный мультиплеер GTA V. Сервер на Node.js, клиентские ресурсы в resources/, серверные скрипты в plugins/. Лёгкий, быстрый, с RCON и собственным форматом конфигов.',
            'icon' => 'gta',
            'image' => 'node:20-slim',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => false,
            'min_slots' => 32,
            'max_slots' => 512,
            'default_slots' => 64,
            'price_per_slot_month' => 4,
            'default_memory_mb' => 4096,
            'min_memory_mb' => 2048,
            'default_disk_mb' => 15360,

            'startup' => [
                'exec' => 'node',
                'args' => ['altv-server'],
                'cwd' => '.',
                'env' => ['NODE_ENV' => 'production'],
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
                'rcon' => ['type' => 'altv', 'port_from' => 'rcon_port'],
                'query' => null,
                'healthcheck' => ['type' => 'process', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'gta/altv/install.sh',
                'timeout' => 900,
            ],

            'config_files' => [
                [
                    'path' => 'altv.config.js',
                    'format' => 'js',
                    'label' => 'altv.config.js',
                    'fields' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'port', 'type' => 'port', 'label' => 'Порт', 'read_only' => true],
                        ['key' => 'maxClients', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 512],
                        ['key' => 'language', 'type' => 'text', 'label' => 'Язык'],
                        ['key' => 'webUrl', 'type' => 'text', 'label' => 'Сайт'],
                    ],
                ],
                [
                    'path' => 'server.cfg',
                    'format' => 'properties',
                    'label' => 'server.cfg',
                    'fields' => [
                        ['key' => 'hostname', 'type' => 'text', 'label' => 'HOSTNAME'],
                        ['key' => 'sv_maxclients', 'type' => 'number', 'label' => 'MAXCLIENTS'],
                        ['key' => 'announce', 'type' => 'bool', 'label' => 'ANNOUNCE'],
                        ['key' => 'password', 'type' => 'text', 'label' => 'PASSWORD'],
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // MTA:SA
    // ─────────────────────────────────────────────────────────────────────

    private static function mta(): array
    {
        return self::game('mta', 'MTA:SA', 'mta', [
            'short_description' => 'Multi Theft Auto: SA, скрипты Lua',
            'description' => 'Самый гибкий мультиплеер GTA SA: сервер на чистом Lua, 1000+ ресурсов, ACL, встроенная админка, античит. Управление через консоль, RCON, GUI и собственные скрипты.',
            'icon' => 'mta',
            'image' => 'i386/alpine:3.20',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 32,
            'max_slots' => 1024,
            'default_slots' => 64,
            'price_per_slot_month' => 4,
            'default_memory_mb' => 2048,
            'min_memory_mb' => 1024,
            'default_disk_mb' => 10240,

            'startup' => [
                'exec' => 'mta_server64',
                'args' => ['-n', 'server'],
                'cwd' => '.',
                'env' => ['LD_LIBRARY_PATH' => '.'],
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
                'rcon' => ['type' => 'mta', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'mta', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 30],
            ],

            'installer' => [
                'type' => 'script',
                'script' => 'mta/install.sh',
                'timeout' => 900,
            ],

            'config_files' => [
                [
                    'path' => 'server.cfg',
                    'format' => 'mta_cfg',
                    'label' => 'server.cfg',
                    'fields' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'maxplayers', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 1024],
                        ['key' => 'port', 'type' => 'port', 'label' => 'Порт игры', 'read_only' => true],
                        ['key' => 'queryport', 'type' => 'port', 'label' => 'Query', 'read_only' => true],
                        ['key' => 'rconport', 'type' => 'port', 'label' => 'RCON', 'read_only' => true],
                        ['key' => 'serverrate', 'type' => 'number', 'label' => 'FPS сервера'],
                        ['key' => 'sync', 'type' => 'bool', 'label' => 'Синхронизация игроков'],
                        ['key' => 'password', 'type' => 'text', 'label' => 'Пароль'],
                        ['key' => 'voice', 'type' => 'bool', 'label' => 'Голосовой чат'],
                        ['key' => 'announce', 'type' => 'bool', 'label' => 'Показывать в браузере'],
                        ['key' => 'language', 'type' => 'select', 'label' => 'Язык',
                            'options' => ['English', 'Русский']],
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Выживачки (SteamCMD)
    // ─────────────────────────────────────────────────────────────────────

    private static function rust(): array
    {
        return self::game('rust', 'Rust', 'survival', [
            'short_description' => 'Выживание, 80+ слотов, карты',
            'description' => 'Rust от Facepunch через SteamCMD. Автогенерация карт, отдельный контейнер на каждый мир, RCON, автоматический wipe по расписанию и бэкапы миров.',
            'icon' => 'rust',
            'uses_steamcmd' => true,
            'steam_appid' => 258550,
            'image' => 'steamcmd/steamcmd:latest',
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 10,
            'max_slots' => 300,
            'default_slots' => 80,
            'price_per_slot_month' => 15,
            'default_memory_mb' => 8192,
            'min_memory_mb' => 4096,
            'default_disk_mb' => 61440,

            'startup' => [
                'exec' => './rustdedicated',
                'args' => [
                    '-logfile', 'console.log',
                    '+server.port', '{game_port}',
                    '+server.queryport', '{query_port}',
                    '+server.rcon.port', '{rcon_port}',
                    '+server.rcon.password', '{rcon_password}',
                    '+server.name', '{server_name}',
                    '+server.maxplayers', '{slots}',
                    '+server.worldsize', '{worldsize}',
                    '+server.saveinterval', '300',
                    '+server.maxcores', '{cpu_percent}',
                    '+server.identity', '{server_name}',
                    '+server.port', '{game_port}',
                ],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGINT',
                'stop_timeout' => 60,
                'rcon' => ['type' => 'rust', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'rust', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 45],
            ],

            'installer' => [
                'type' => 'steamcmd',
                'app_id' => 258550,
                'anonymous' => true,
                'script' => 'steamcmd/install-app.sh',
                'post_install' => 'rust/post-install.sh',
                'timeout' => 5400,
            ],

            'config_files' => [
                [
                    'path' => 'serverconfig.cfg',
                    'format' => 'rust_cfg',
                    'label' => 'Конфигурация сервера',
                    'fields' => [
                        ['key' => 'server.title', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'server.maxplayers', 'type' => 'number', 'label' => 'Слотов'],
                        ['key' => 'server.worldsize', 'type' => 'select', 'label' => 'Размер мира',
                            'options' => [1000, 2000, 3000, 4000, 5000, 6000, 8000, 10000]],
                        ['key' => 'server.saveinterval', 'type' => 'number', 'label' => 'Интервал сохранения, с'],
                        ['key' => 'server.pvp', 'type' => 'bool', 'label' => 'PvP'],
                        ['key' => 'server.maxteams', 'type' => 'number', 'label' => 'Макс. команд'],
                        ['key' => 'server.wipeid', 'type' => 'text', 'label' => 'Префикс вайпа'],
                        ['key' => 'server.updateinterval', 'type' => 'number', 'label' => 'Интервал обновления, мин'],
                    ],
                ],
            ],
        ]);
    }

    private static function unturned(): array
    {
        return self::game('unturned', 'Unturned', 'survival', [
            'short_description' => 'Зомби-выживание, 24 слота',
            'description' => 'Unturned: SteamCMD-установка, Commands.dat для настроек, ротация карт по таймеру, Rocket-mod и плагины в папке Server/Plugins.',
            'icon' => 'unturned',
            'uses_steamcmd' => true,
            'steam_appid' => 1110390,
            'image' => 'steamcmd/steamcmd:latest',
            'supports_plugins' => true,
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 4,
            'max_slots' => 24,
            'default_slots' => 12,
            'price_per_slot_month' => 20,
            'default_memory_mb' => 4096,
            'min_memory_mb' => 2048,
            'default_disk_mb' => 40960,

            'startup' => [
                'exec' => 'bash',
                'args' => [
                    'ServerHelper.sh',
                    '+InternetServer/MaxPlayers/{slots}',
                    '+GamePort/{game_port}',
                    '+QueryPort/{query_port}',
                    '+VAC_Secure/1',
                    '+GatewayIP/{server_ip}',
                    '+Port/{game_port}',
                    '+secureserver/{rcon_password}',
                ],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 45,
                'rcon' => ['type' => 'unturned', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'unturned', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 45],
            ],

            'installer' => [
                'type' => 'steamcmd',
                'app_id' => 1110390,
                'anonymous' => true,
                'script' => 'steamcmd/install-app.sh',
                'post_install' => 'unturned/post-install.sh',
                'timeout' => 3600,
            ],

            'config_files' => [
                [
                    'path' => 'Server/Commands.dat',
                    'format' => 'unturned_dat',
                    'label' => 'Commands.dat',
                    'fields' => [
                        ['key' => 'Name', 'type' => 'text', 'label' => 'Название сервера'],
                        ['key' => 'MaxPlayers', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 24],
                        ['key' => 'Port', 'type' => 'port', 'label' => 'Порт', 'read_only' => true],
                        ['key' => 'QueryPort', 'type' => 'port', 'label' => 'Query', 'read_only' => true],
                        ['key' => 'WelcomeText', 'type' => 'text', 'label' => 'Приветствие'],
                        ['key' => 'Password', 'type' => 'text', 'label' => 'Пароль'],
                        ['key' => 'Map', 'type' => 'text', 'label' => 'Карта (ротация)'],
                        ['key' => 'MapTime', 'type' => 'number', 'label' => 'Смена карты, мин'],
                        ['key' => 'PvE', 'type' => 'bool', 'label' => 'PvE'],
                        ['key' => 'BattlEye', 'type' => 'bool', 'label' => 'BattlEye'],
                    ],
                ],
            ],
        ]);
    }

    private static function ark(): array
    {
        return self::game('ark', 'ARK: Survival Evolved', 'survival', [
            'short_description' => 'Динозавры, 70+ слотов, тяжёлый',
            'description' => 'ARK через SteamCMD. Требователен к ресурсам: 8+ ГБ RAM на сервер. Удобный планировщик для дневных циклов, автоматические сохранения и бэкапы через GameUserSettings.',
            'icon' => 'ark',
            'uses_steamcmd' => true,
            'steam_appid' => 346350,
            'image' => 'steamcmd/steamcmd:latest',
            'supports_rcon' => true,
            'supports_query' => true,
            'min_slots' => 10,
            'max_slots' => 150,
            'default_slots' => 70,
            'price_per_slot_month' => 18,
            'default_memory_mb' => 12288,
            'min_memory_mb' => 8192,
            'default_disk_mb' => 81920,

            'startup' => [
                'exec' => 'ShooterGameServer',
                'args' => [
                    '{map}',
                    '-port={game_port}',
                    '-QueryPort={query_port}',
                    '-ServerAdminPassword={rcon_password}',
                    '-log',
                    '-adminpassword={rcon_password}',
                ],
                'cwd' => '.',
                'user' => 'gamedock',
                'stop_signal' => 'SIGINT',
                'stop_timeout' => 90,
                'rcon' => ['type' => 'ark', 'port_from' => 'rcon_port'],
                'query' => ['type' => 'ark', 'port_from' => 'query_port'],
                'healthcheck' => ['type' => 'query', 'interval' => 60],
            ],

            'installer' => [
                'type' => 'steamcmd',
                'app_id' => 346350,
                'anonymous' => true,
                'script' => 'steamcmd/install-app.sh',
                'post_install' => 'ark/post-install.sh',
                'timeout' => 7200,
            ],

            'config_files' => [
                [
                    'path' => 'ShooterGame/Saved/Config/LinuxServer/GameUserSettings.ini',
                    'format' => 'ini',
                    'label' => 'GameUserSettings.ini',
                    'fields' => [
                        ['key' => 'ServerName', 'type' => 'text', 'label' => 'Название'],
                        ['key' => 'MaxPlayers', 'type' => 'number', 'label' => 'Слотов', 'min' => 1, 'max' => 150],
                        ['key' => 'ServerAdminPassword', 'type' => 'password', 'label' => 'Пароль админа'],
                        ['key' => 'AdminPassword', 'type' => 'password', 'label' => 'Пароль RCON'],
                        ['key' => 'Port', 'type' => 'port', 'label' => 'Порт', 'read_only' => true],
                        ['key' => 'QueryPort', 'type' => 'port', 'label' => 'Query', 'read_only' => true],
                        ['key' => 'RCONPort', 'type' => 'port', 'label' => 'RCON', 'read_only' => true],
                        ['key' => 'bAllowCancellations', 'type' => 'bool', 'label' => 'Разрешить отмену присоединения'],
                        ['key' => 'DifficultyOverride', 'type' => 'number', 'label' => 'Сложность 0-1'],
                        ['key' => 'TributeExpirationTime', 'type' => 'number', 'label' => 'Вре жизни заброшенного трейла, мин'],
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Шаблон
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Заполняет значения по умолчанию, чтобы каждое описание игры было
     * одинаково полным и админка не получала null в необязательных полях.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function game(string $slug, string $name, string $family, array $data = []): array
    {
        return array_replace([
            'family' => $family,
            'description' => null,
            'short_description' => null,
            'icon' => null,
            'banner' => null,
            'trailer_url' => null,
            'tags' => null,

            'startup' => [
                'exec' => '',
                'args' => [],
                'cwd' => '.',
                'env' => [],
                'user' => 'gamedock',
                'stop_signal' => 'SIGTERM',
                'stop_timeout' => 30,
                'rcon' => null,
                'query' => null,
                'healthcheck' => ['type' => 'process', 'interval' => 30],
            ],

            'installer' => ['type' => 'none', 'timeout' => 1800],

            'bootstrap_files' => [],
            'config_files' => [],

            'image' => null,
            'runtime_overrides' => null,
            'working_user' => 'gamedock',
            'default_env' => [],

            'uses_steamcmd' => false,
            'steam_appid' => null,
            'default_branch' => null,

            'min_slots' => 1,
            'max_slots' => 100,
            'default_slots' => 10,
            'slot_step' => 1,
            'price_per_slot_month' => 5,

            'default_memory_mb' => 1024,
            'min_memory_mb' => 512,
            'default_cpu_percent' => 50,
            'default_disk_mb' => 10240,

            'supports_rcon' => false,
            'supports_query' => false,
            'supports_bedrock' => false,
            'supports_plugins' => false,
            'supports_auto_update' => true,
            'supports_custom_builds' => false,
            'builds' => null,
            'supports_cron' => true,

            'is_custom' => false,
            'is_active' => true,
            'is_public' => true,
            'is_featured' => false,
            'sort' => 0,
        ], $data) + ['slug' => $slug, 'name' => $name];
    }
}
