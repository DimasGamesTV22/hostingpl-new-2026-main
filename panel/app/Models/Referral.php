<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'referrer_id', 'referred_id', 'status', 'reward_referrer', 'reward_referred',
        'order_amount', 'trigger', 'rejected_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_referrer' => 'decimal:2',
            'reward_referred' => 'decimal:2',
            'order_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'green',
            self::STATUS_PENDING => 'yellow',
            default => 'red',
        };
    }

    public function statusLabel(): string
    {
        return __('referral.status.'.$this->status);
    }
}
