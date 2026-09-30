<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'link', 'level', 'meta', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function icon(): string
    {
        return match ($this->type) {
            'server.down' => 'alert-triangle',
            'server.expiring' => 'clock',
            'server.suspended' => 'pause-circle',
            'billing.low_balance' => 'wallet',
            'billing.paid' => 'check-circle',
            'ticket.reply' => 'message-square',
            'promo' => 'gift',
            'referral' => 'users',
            'backup.done' => 'archive',
            'security.login' => 'shield',
            default => 'bell',
        };
    }

    public function color(): string
    {
        return match ($this->level) {
            'success' => 'green',
            'warning' => 'yellow',
            'danger' => 'red',
            default => 'blue',
        };
    }
}
