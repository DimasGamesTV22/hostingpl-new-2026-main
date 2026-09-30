<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    use HasFactory;

    public const EVENTS = [
        'server.created', 'server.deleted', 'server.started', 'server.stopped', 'server.crashed',
        'server.expiring', 'server.suspended', 'server.resumed',
        'payment.succeeded', 'payment.failed',
        'user.registered', 'user.blocked',
        'backup.completed',
        'node.offline', 'node.online',
    ];

    protected $fillable = ['user_id', 'name', 'url', 'secret', 'events', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_fired_at' => 'datetime',
            'failures' => 'integer',
            'success_count' => 'integer',
            'last_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }

    public function healthColor(): string
    {
        if (! $this->is_active) {
            return 'gray';
        }
        if ($this->failures > 0) {
            return 'red';
        }
        if ($this->last_status && $this->last_status >= 200 && $this->last_status < 300) {
            return 'green';
        }

        return 'yellow';
    }
}
