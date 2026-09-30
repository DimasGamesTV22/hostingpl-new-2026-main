<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'webhook_id', 'event', 'payload', 'response_status', 'duration_ms', 'error', 'success', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
            'created_at' => 'datetime',
            'response_status' => 'integer',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
