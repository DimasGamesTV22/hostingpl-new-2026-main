<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginCode extends Model
{
    public const PURPOSE_VERIFY_EMAIL = 'verify_email';
    public const PURPOSE_LOGIN_2FA = 'login_2fa';
    public const PURPOSE_DEVICE_LOGIN = 'device_login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSES = [
        self::PURPOSE_VERIFY_EMAIL,
        self::PURPOSE_LOGIN_2FA,
        self::PURPOSE_DEVICE_LOGIN,
        self::PURPOSE_PASSWORD_RESET,
    ];

    protected $fillable = ['user_id', 'code_hash', 'purpose', 'attempts', 'ip', 'expires_at', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($code)), (string) config('app.key'));
    }

    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->attempts < 5;
    }
}
