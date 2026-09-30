<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $table = 'faqs';

    protected $fillable = ['question', 'answer', 'category', 'is_published', 'sort'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort');
    }
}
