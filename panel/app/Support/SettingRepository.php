<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Доступ к настройкам GameDock.
 *
 * Значения из config/hosting.php — база (можно править файл и деплоить).
 * Значения из таблицы `settings` — переопределения из админки, они важнее.
 * Всё складывается в один кэш на 5 минут, сбрасывается при сохранении в админке.
 */
class SettingRepository
{
    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->all())) {
            return $this->all()[$key];
        }

        return config($key, $default);
    }

    /**
     * Все значения с учётом переопределений.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $cached = Cache::get(Setting::CACHE_KEY);

        if (is_array($cached)) {
            return $this->overrides = $cached;
        }

        $overrides = [];

        Setting::query()
            ->select(['key', 'value', 'type'])
            ->get()
            ->each(function (Setting $setting) use (&$overrides) {
                $overrides[$setting->key] = $setting->castValue();
            });

        Cache::put(Setting::CACHE_KEY, $overrides, Setting::CACHE_TTL);

        return $this->overrides = $overrides;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        $this->overrides = null;
        Setting::flushCache();
    }

    /** Узлы рантайма, реально поддерживаемые панелью. */
    public function availableRuntimes(): array
    {
        return array_keys((array) config('hosting.runtime.available'));
    }

    /** Режимы работы с нодами. */
    public function nodeModes(): array
    {
        return ['single', 'manual', 'auto'];
    }
}
