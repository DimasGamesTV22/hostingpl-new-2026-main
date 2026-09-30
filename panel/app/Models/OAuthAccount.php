<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'provider', 'provider_id', 'nickname', 'avatar',
        'access_token', 'refresh_token', 'expires_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar ?: $this->user?->avatar_url;
    }
}
