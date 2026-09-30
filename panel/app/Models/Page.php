<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Page extends Model
{
    protected $fillable = ['slug', 'title', 'body', 'meta', 'is_published', 'sort'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_published' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if (blank($p->slug)) {
                $p->slug = Str::slug($p->title);
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort');
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta, $key, $default);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
