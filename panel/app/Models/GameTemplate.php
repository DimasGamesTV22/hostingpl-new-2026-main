<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id', 'slug', 'name', 'type', 'description', 'version', 'game_version',
        'source_type', 'source_url', 'builtin_path', 's3_key', 'target_path',
        'env_replace', 'post_commands', 'size_bytes', 'checksum', 'checksum_urls',
        'is_official', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'env_replace' => 'array',
            'post_commands' => 'array',
            'checksum_urls' => 'array',
            'is_official' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function installs(): HasMany
    {
        return $this->hasMany(GameTemplateInstall::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('type')->orderBy('sort')->orderBy('name');
    }

    /** Ссылка на скачивание с подстановкой версии (Modrinth / CurseForge / Paper API). */
    public function resolveUrl(?string $gameVersion = null): ?string
    {
        if ($this->source_type !== 'url' || blank($this->source_url)) {
            return $this->source_url;
        }

        if ($gameVersion && ! str_contains($this->source_url, '{version}')) {
            return $this->source_url;
        }

        return str_replace('{version}', $gameVersion ?? $this->game_version ?? 'latest', $this->source_url);
    }

    public function targetPath(): string
    {
        return $this->target_path ?: match ($this->type) {
            'plugin' => 'plugins/',
            'mod', 'datapack' => 'mods/',
            'build' => '.',
            default => '.',
        };
    }
}
