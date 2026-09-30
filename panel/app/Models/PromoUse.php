<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoUse extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_code_id', 'user_id', 'server_id', 'order_id', 'deposit_id',
        'discount', 'days_added', 'bonus_applied', 'slots_added', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'bonus_applied' => 'decimal:2',
            'days_added' => 'integer',
            'slots_added' => 'integer',
        ];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
