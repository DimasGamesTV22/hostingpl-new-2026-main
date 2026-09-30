<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserTransaction extends Model
{
    use HasFactory;

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAW = 'withdraw';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_REFUND = 'refund';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_REFERRAL = 'referral';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CHARGE = 'charge';

    public const TYPES = [
        self::TYPE_DEPOSIT, self::TYPE_WITHDRAW, self::TYPE_PURCHASE,
        self::TYPE_REFUND, self::TYPE_BONUS, self::TYPE_REFERRAL,
        self::TYPE_ADJUSTMENT, self::TYPE_CHARGE,
    ];

    public const DIR_CREDIT = 'credit';
    public const DIR_DEBIT = 'debit';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id', 'type', 'direction', 'amount', 'balance_before', 'balance_after',
        'currency', 'status', 'source', 'reference', 'title', 'description',
        'server_id', 'order_id', 'meta', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            $t->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('direction', self::DIR_CREDIT);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('direction', self::DIR_DEBIT);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIR_CREDIT;
    }

    public function signedAmount(): string
    {
        $amount = number_format((float) $this->amount, 2);

        return ($this->isCredit() ? '+' : '−').$amount.' '.$this->currency;
    }

    public function color(): string
    {
        return match ($this->type) {
            self::TYPE_DEPOSIT => 'green',
            self::TYPE_BONUS, self::TYPE_REFERRAL => 'emerald',
            self::TYPE_PURCHASE, self::TYPE_CHARGE => 'gray',
            self::TYPE_REFUND => 'sky',
            default => 'gray',
        };
    }

    public function typeLabel(): string
    {
        return __('billing.transactions.types.'.$this->type);
    }
}
