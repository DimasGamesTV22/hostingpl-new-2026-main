<?php

declare(strict_types=1);

use App\Support\SettingRepository;
use App\Support\Totp;

if (! function_exists('setting')) {
    /**
     * Значение настройки GameDock.
     *
     * Приоритет: значение из БД (админка) → config/hosting.php → переданный default.
     * Пример: setting('hosting.billing.currency', 'RUB')
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingRepository::class)->get($key, $default);
    }
}

if (! function_exists('setting_bool')) {
    function setting_bool(string $key, bool $default = false): bool
    {
        return (bool) setting($key, $default);
    }
}

if (! function_exists('setting_int')) {
    function setting_int(string $key, int $default = 0): int
    {
        return (int) setting($key, $default);
    }
}

if (! function_exists('setting_float')) {
    function setting_float(string $key, float $default = 0.0): float
    {
        return (float) setting($key, $default);
    }
}

if (! function_exists('setting_array')) {
    /**
     * @return array<mixed>
     */
    function setting_array(string $key, array $default = []): array
    {
        $value = setting($key, $default);

        return is_array($value) ? $value : (array) $value;
    }
}

if (! function_exists('money')) {
    /** Форматирует сумму в валюте панели. */
    function money(float|int|string|null $amount, bool $withSymbol = true, ?string $currency = null): string
    {
        $amount = (float) $amount;
        $currency ??= (string) setting('hosting.billing.currency', 'RUB');
        $symbol = $currency === 'RUB' ? (string) setting('hosting.billing.currency_symbol', '₽') : $currency;
        $decimals = (int) setting('hosting.billing.round_decimals', 2);

        $formatted = number_format($amount, $decimals, ',', ' ');

        return $withSymbol ? trim($formatted.' '.$symbol) : $formatted;
    }
}

if (! function_exists('bytes_human')) {
    function bytes_human(int|float|null $bytes, int $precision = 1): string
    {
        $bytes = (float) $bytes;

        if ($bytes <= 0) {
            return '0 Б';
        }

        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $precision).' '.$units[$power];
    }
}

if (! function_exists('mb_gb')) {
    /** 1024 МБ → «1 ГБ» */
    function mb_gb(int $mb, int $precision = 0): string
    {
        if ($mb >= 1024) {
            return round($mb / 1024, $precision).' ГБ';
        }

        return $mb.' МБ';
    }
}

if (! function_exists('duration_human')) {
    function duration_human(int|float $seconds): string
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return '—';
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($d > 0) {
            $parts[] = $d.' дн';
        }
        if ($h > 0) {
            $parts[] = $h.' ч';
        }
        if ($m > 0 && $d === 0) {
            $parts[] = $m.' мин';
        }

        return $parts ? implode(' ', array_slice($parts, 0, 2)) : $seconds.' сек';
    }
}

if (! function_exists('client_ip')) {
    function client_ip(): string
    {
        return (string) request()->ip();
    }
}

if (! function_exists('ip_hash')) {
    /** Хэш IP для счётчиков брутфорса (не храним IP в открытом виде). */
    function ip_hash(?string $ip = null): string
    {
        return hash_hmac('sha256', $ip ?? client_ip(), (string) config('app.key'));
    }
}

if (! function_exists('totp')) {
    function totp(): Totp
    {
        return app(Totp::class);
    }
}

if (! function_exists('user_agent_device')) {
    function user_agent_device(?string $ua = null): string
    {
        return App\Models\UserSession::detectDevice($ua ?? (string) request()->userAgent());
    }
}

if (! function_exists('game_image_url')) {
    /** Иконка игры: сперва заданная, иначе заглушка по семейству. */
    function game_image_url(?string $icon, ?string $family = null): string
    {
        if (! blank($icon)) {
            return str_starts_with($icon, 'http') || str_starts_with($icon, '/')
                ? $icon
                : asset('img/games/'.$icon.'.png');
        }

        return asset('img/games/'.($family ?: 'default').'.png');
    }
}

if (! function_exists('config_get')) {
    /**
     * Чтение произвольного значения из config/hosting.php с учётом массивов.
     * Например: config_get('resources.defaults.memory_mb')
     */
    function config_get(string $path, mixed $default = null): mixed
    {
        return data_get((array) config('hosting'), $path, $default);
    }
}

if (! function_exists('node_mode')) {
    function node_mode(): string
    {
        return (string) setting('hosting.node_mode', 'auto');
    }
}

if (! function_exists('default_runtime')) {
    function default_runtime(): string
    {
        return (string) setting('hosting.runtime.default', 'docker');
    }
}

if (! function_exists('queue_name')) {
    function queue_name(string $logical): string
    {
        return (string) setting('hosting.queue.'.$logical, $logical);
    }
}
