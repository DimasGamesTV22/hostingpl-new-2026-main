<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id', 'user_id', 'is_staff', 'author_name', 'body', 'attachments',
        'is_internal_note', 'ip', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_staff' => 'boolean',
            'is_internal_note' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisible(Builder $query, bool $isStaff): Builder
    {
        return $query->when(! $isStaff, fn (Builder $q) => $q->where('is_internal_note', false));
    }

    public function isSystem(): bool
    {
        return $this->author_name === 'Система';
    }
}
