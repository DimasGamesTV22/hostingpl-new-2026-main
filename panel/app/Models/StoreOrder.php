<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StoreOrder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'server_id', 'product', 'label', 'quantity', 'unit',
        'unit_price', 'total', 'status', 'transaction_id', 'meta', 'paid_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'quantity' => 'integer',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $o) {
            $o->uuid ??= (string) Str::uuid();
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(UserTransaction::class, 'transaction_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isRecurring(): bool
    {
        // Повторные услуги (доп. слоты, RAM) начисляются каждый период
        return in_array($this->product, ['extra_slots', 'extra_memory', 'extra_disk', 'extra_cpu', 'support'], true);
    }

    public function isOneTime(): bool
    {
        return in_array($this->product, ['port', 'backup_slots'], true);
    }

    public function totalFormatted(): string
    {
        return number_format((float) $this->total, 2).' ₽';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_APPLIED, self::STATUS_PAID => 'green',
            self::STATUS_PENDING => 'yellow',
            self::STATUS_FAILED, self::STATUS_CANCELLED => 'red',
            default => 'gray',
        };
    }
}
