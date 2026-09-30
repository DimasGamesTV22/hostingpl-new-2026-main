<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\GameCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'family', 'description', 'short_description', 'icon', 'banner',
        'trailer_url', 'tags', 'startup', 'installer', 'bootstrap_files', 'config_files',
        'image', 'runtime_overrides', 'working_user', 'default_env',
        'uses_steamcmd', 'steam_appid', 'default_branch',
        'min_slots', 'max_slots', 'default_slots', 'slot_step', 'price_per_slot_month',
        'default_memory_mb', 'min_memory_mb', 'default_cpu_percent', 'default_disk_mb',
        'supports_rcon', 'supports_query', 'supports_bedrock', 'supports_plugins',
        'supports_auto_update', 'supports_custom_builds', 'builds', 'supports_cron',
        'is_custom', 'is_active', 'is_public', 'is_featured', 'sort', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'startup' => 'array',
            'installer' => 'array',
            'bootstrap_files' => 'array',
            'config_files' => 'array',
            'runtime_overrides' => 'array',
            'default_env' => 'array',
            'builds' => 'array',
            'tags' => 'array',
            'uses_steamcmd' => 'boolean',
            'supports_rcon' => 'boolean',
            'supports_query' => 'boolean',
            'supports_bedrock' => 'boolean',
            'supports_plugins' => 'boolean',
            'supports_auto_update' => 'boolean',
            'supports_custom_builds' => 'boolean',
            'supports_cron' => 'boolean',
            'is_custom' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'price_per_slot_month' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $game) {
            if (blank($game->slug)) {
                $game->slug = Str::slug($game->name);
            }
        });
    }

    // ── Отношения ───────────────────────────────────────────────────────

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(GameTemplate::class);
    }

    public function tariffs(): BelongsToMany
    {
        return $this->belongsToMany(Tariff::class, 'tariff_games', 'game_id', 'tariff_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Скоупы ──────────────────────────────────────────────────────────

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_featured')->orderBy('sort')->orderBy('name');
    }

    public function scopeFamily(Builder $query, string $family): Builder
    {
        return $query->where('family', $family);
    }

    // ── Доступ ──────────────────────────────────────────────────────────

    /** Разрешённые в ключе startup поля, остальные отбрасываются при сохранении. */
    public static function startupSchema(): array
    {
        return [
            'exec' => 'string',
            'args' => 'array',
            'cwd' => 'string',
            'env' => 'array',
            'user' => 'string',
            'stop_signal' => 'string',
            'stop_timeout' => 'int',
            'rcon' => 'array',
            'query' => 'array',
            'healthcheck' => 'array',
        ];
    }

    public function startupValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->startup, $key, $default);
    }

    /** Собирает команду запуска с подстановкой ресурсов. */
    public function buildCommand(array $overrides = []): array
    {
        $startup = array_replace_recursive($this->startup ?? [], $overrides);
        $exec = $startup['exec'] ?? '';
        $args = $startup['args'] ?? [];

        return [
            'exec' => $exec,
            'args' => is_array($args) ? $args : [],
            'cwd' => $startup['cwd'] ?? '.',
            'env' => $startup['env'] ?? [],
            'user' => $startup['user'] ?? $this->working_user,
            'stop_signal' => $startup['stop_signal'] ?? 'SIGTERM',
            'stop_timeout' => (int) ($startup['stop_timeout'] ?? 30),
        ];
    }

    public function queryType(): ?string
    {
        $type = $this->startupValue('query.type');

        return $type === 'none' ? null : $type;
    }

    public function rconType(): ?string
    {
        return $this->supports_rcon ? ($this->startupValue('rcon.type') ?? 'source') : null;
    }

    public function hasSteamBuilds(): bool
    {
        return $this->supports_custom_builds && ! empty($this->builds);
    }

    /** Список доступных сборок: [{id, name, branch, image, startup_overrides}] */
    public function buildList(): array
    {
        return array_map(static function ($b) {
            return is_array($b) ? $b : (array) json_decode((string) $b, true);
        }, $this->builds ?? []);
    }

    public function build(string $id): ?array
    {
        foreach ($this->buildList() as $b) {
            if (($b['id'] ?? null) === $id) {
                return $b;
            }
        }

        return null;
    }

    public function configFiles(): array
    {
        return $this->config_files ?? [];
    }

    public function configFile(string $path): ?array
    {
        foreach ($this->configFiles() as $f) {
            if (($f['path'] ?? null) === $path) {
                return $f;
            }
        }

        return null;
    }

    // ── Отображение ─────────────────────────────────────────────────────

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => trim((string) $value),
        );
    }

    protected function slotsRange(): Attribute
    {
        return Attribute::get(fn (): string => $this->min_slots.'–'.$this->max_slots);
    }

    protected function pricePerSlotFormatted(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->price_per_slot_month, 0).' '.config('hosting.billing.currency_symbol'));
    }

    public function accentColor(): string
    {
        return match ($this->family) {
            'minecraft' => 'green',
            'cs2', 'cs' => 'blue',
            'gta' => 'yellow',
            'mta' => 'indigo',
            'survival' => 'red',
            default => 'gray',
        };
    }

    // ── Каталог по умолчанию ────────────────────────────────────────────

    public static function defaults(): array
    {
        return GameCatalog::definitions();
    }
}
