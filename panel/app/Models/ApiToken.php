<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'user_id', 'name', 'token_prefix', 'token_hash', 'abilities',
        'ip_whitelist', 'last_used_ip', 'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'ip_whitelist' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ! $this->isExpired();
    }

    public static function generate(string $plain): array
    {
        return [
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
        ];
    }

    public function canBeUsedFrom(?string $ip): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (blank($this->ip_whitelist)) {
            return true;
        }

        foreach ($this->ip_whitelist as $cidr) {
            if (IpBan::ipInCidr((string) $ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }
}
