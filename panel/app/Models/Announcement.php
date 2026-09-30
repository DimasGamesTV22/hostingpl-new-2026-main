<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Announcement extends Model
{
    protected $fillable = ['slug', 'title', 'summary', 'body', 'image', 'category', 'is_pinned', 'is_published', 'published_at', 'author_id'];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (blank($a->slug)) {
                $a->slug = Str::slug($a->title);
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
