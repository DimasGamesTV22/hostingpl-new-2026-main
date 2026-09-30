<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Deposit extends Model
{
    use HasFactory;

    public const METHOD_WALLET = 'wallet';
    public const METHOD_YOOKASSA = 'yookassa';
    public const METHOD_TINKOFF = 'tinkoff';
    public const METHOD_CRYPTOBOT = 'cryptobot';
    public const METHOD_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id', 'amount', 'currency', 'method', 'status',
        'invoice_url', 'provider_id', 'idempotency_key', 'meta', 'error', 'paid_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast()
            && in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function methodLabel(): string
    {
        return config('hosting.payments.methods.'.$this->method.'.label', $this->method);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'green',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_PENDING => 'yellow',
            self::STATUS_FAILED, self::STATUS_EXPIRED => 'red',
            default => 'gray',
        };
    }

    public function amountFormatted(): string
    {
        return number_format((float) $this->amount, 2).' '.$this->currency;
    }
}
