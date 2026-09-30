<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'user_id', 'server_id', 'department_id', 'assigned_to',
        'subject', 'category', 'priority', 'status',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'is_archived' => 'boolean',
            'messages_count' => 'integer',
            'unread_by_staff' => 'integer',
            'unread_by_user' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->uuid ??= (string) Str::uuid();
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PENDING, self::STATUS_ANSWERED]);
    }

    public function scopeForQueue(Builder $query): Builder
    {
        return $query->open()->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('last_reply_at');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isWaitingForStaff(): bool
    {
        return $this->last_reply_by_staff === null
            || ($this->last_reply_at && $this->last_reply_by_staff < $this->last_reply_at);
    }

    public function priorityColor(): string
    {
        return match ($this->priority) {
            self::PRIORITY_URGENT => 'red',
            self::PRIORITY_HIGH => 'yellow',
            self::PRIORITY_LOW => 'gray',
            default => 'blue',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'green',
            self::STATUS_PENDING => 'yellow',
            self::STATUS_ANSWERED => 'blue',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return __('support.tickets.status.'.$this->status);
    }

    public function priorityLabel(): string
    {
        return __('support.tickets.priority.'.$this->priority);
    }

    public function responseTimeHuman(): ?string
    {
        return $this->first_response_at?->diffForHumans();
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
