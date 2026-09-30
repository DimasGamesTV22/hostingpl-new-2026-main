<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ServerCharge extends Model
{
    use HasFactory;

    public const TYPE_PERIOD = 'period';
    public const TYPE_EXTRA_SLOTS = 'extra_slots';
    public const TYPE_PORT = 'port';
    public const TYPE_UPGRADE = 'upgrade';
    public const TYPE_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'user_id', 'server_id', 'tariff_id', 'transaction_id',
        'type', 'amount', 'currency', 'status', 'period_start', 'period_end', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(UserTransaction::class, 'transaction_id');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->period_end !== null
            && Carbon::parse($this->period_end)->isPast();
    }

    public function isRecurring(): bool
    {
        return $this->type === self::TYPE_PERIOD;
    }

    public function amountFormatted(): string
    {
        return number_format((float) $this->amount, 2).' '.$this->currency;
    }
}
