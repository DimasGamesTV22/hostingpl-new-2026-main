<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'gamedock:settings';

    public const CACHE_TTL = 300;

    protected $fillable = ['group', 'key', 'value', 'type', 'is_public', 'label', 'hint', 'validation', 'options', 'sort'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_public' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.':public');
    }

    /** @return array<string, mixed> */
    public static function allCached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function () {
            $out = [];
            foreach (static::all() as $setting) {
                $out[$setting->key] = $setting->castValue();
            }

            return $out;
        });
    }

    /** @return array<string, mixed> */
    public static function publicCached(): array
    {
        return Cache::remember(self::CACHE_KEY.':public', self::CACHE_TTL, static function () {
            $out = [];
            foreach (static::where('is_public', true)->get() as $setting) {
                $out[$setting->key] = $setting->castValue();
            }

            return $out;
        });
    }

    public function castValue(): mixed
    {
        $value = $this->value;

        return match ($this->type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode((string) $value, true) ?? [],
            default => $value,
        };
    }

    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}
