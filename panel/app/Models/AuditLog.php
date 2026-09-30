<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'actor_name', 'actor_role', 'action', 'subject_type',
        'subject_id', 'description', 'old_values', 'new_values', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForAction(Builder $query, string $prefix): Builder
    {
        return $query->where('action', 'like', $prefix.'%');
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    public function color(): string
    {
        return match (true) {
            str_starts_with($this->action, 'admin.') => 'yellow',
            str_starts_with($this->action, 'auth.') => 'blue',
            str_starts_with($this->action, 'billing.') => 'green',
            str_starts_with($this->action, 'server.') => 'indigo',
            str_contains($this->action, 'delete') || str_contains($this->action, 'block') => 'red',
            default => 'gray',
        };
    }
}
