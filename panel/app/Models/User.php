<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public const ROLE_USER = 'user';
    public const ROLE_SUPPORT = 'support';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLES = [
        self::ROLE_USER, self::ROLE_SUPPORT, self::ROLE_MODERATOR,
        self::ROLE_ADMIN, self::ROLE_SUPERADMIN,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    /** Уровни ролей — используются в политиках и middleware. */
    public const ROLE_LEVELS = [
        self::ROLE_USER => 0,
        self::ROLE_SUPPORT => 10,
        self::ROLE_MODERATOR => 20,
        self::ROLE_ADMIN => 30,
        self::ROLE_SUPERADMIN => 100,
    ];

    protected $fillable = [
        'uuid', 'name', 'username', 'email', 'password', 'role', 'is_staff',
        'balance', 'currency', 'referral_code', 'referred_by',
        'locale', 'timezone', 'avatar_url', 'about', 'note',
        'contact_email', 'contact_telegram', 'newsletter', 'terms_accepted_at',
        'status', 'block_reason',
    ];

    protected $hidden = [
        'password', 'remember_token', 'note',
        'two_factor_secret', 'two_factor_recovery',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'two_factor_2fa_pending_until' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'blocked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'balance_updated_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'balance' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'is_staff' => 'boolean',
            'is_demo' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed' => 'boolean',
            'newsletter' => 'boolean',
            'referral_count' => 'integer',
            'login_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->uuid ??= (string) Str::uuid();
            $user->referral_code ??= static::generateReferralCode();
            $user->currency ??= config('hosting.billing.currency', 'RUB');
            $user->locale ??= config('hosting.locale.default', 'ru');
            $user->timezone ??= config('hosting.locale.timezone', 'Europe/Moscow');
        });
    }

    // ── Отношения ───────────────────────────────────────────────────────

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(UserTransaction::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ServerCharge::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(PanelNotification::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    public function referrer(): HasOne
    {
        return $this->hasOne(self::class, 'id', 'referred_by');
    }

    public function referralsSent(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referralsReceived(): HasMany
    {
        return $this->hasMany(Referral::class, 'referred_id');
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(ServerSubAccount::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function twoFactorRecoveryCodes(): array
    {
        return $this->two_factor_recovery
            ? json_decode($this->two_factor_recovery, true) ?: []
            : [];
    }

    // ── Роли и права ────────────────────────────────────────────────────

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLevel(): int
    {
        return self::ROLE_LEVELS[$this->role] ?? 0;
    }

    public function isAdmin(): bool
    {
        return $this->roleLevel() >= self::ROLE_LEVELS[self::ROLE_ADMIN];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function isStaff(): bool
    {
        return $this->is_staff || $this->roleLevel() >= self::ROLE_LEVELS[self::ROLE_SUPPORT];
    }

    public function atLeast(string $role): bool
    {
        return $this->roleLevel() >= (self::ROLE_LEVELS[$role] ?? 999);
    }

    // ── Деньги ──────────────────────────────────────────────────────────

    public function hasBalance(float $amount): bool
    {
        return (float) $this->balance >= $amount;
    }

    /** Сколько серверов ещё разрешено создать по глобальному лимиту. */
    public function serverQuotaLeft(): int
    {
        $limit = (int) setting('hosting.account.max_servers_per_user', 20);
        $used = $this->servers()->count();

        return max(0, $limit - $used);
    }

    public function trialActive(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function statusLabel(): string
    {
        return __('users.status.'.$this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'green',
            self::STATUS_BLOCKED => 'red',
            default => 'gray',
        };
    }

    public function roleLabel(): string
    {
        return __('users.roles.'.$this->role);
    }

    // ── Атрибуты ────────────────────────────────────────────────────────

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : trim($value),
        );
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim((string) $this->name);
            if ($name === '') {
                return '?';
            }
            $parts = preg_split('/\s+/u', $name) ?: [];
            $letters = array_map(
                static fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)),
                array_slice($parts, 0, 2),
            );

            return implode('', $letters);
        });
    }

    public function routeName(): string
    {
        return $this->username ?: 'u'.$this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'username';
    }

    public function getRouteKey(): string
    {
        return $this->routeName();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery();

        if ($field === 'username' || $field === null) {
            $query->where('username', $value);

            if ($query->exists()) {
                return $query->first();
            }
        }

        if (is_numeric($value)) {
            return $this->newQuery()->where('id', (int) $value)->first();
        }

        return null;
    }

    // ── Скоупы ──────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('is_staff', true);
    }

    public function scopeRole(Builder $query, string ...$roles): Builder
    {
        return $query->whereIn('role', $roles);
    }

    public function scopeOnline(Builder $query, int $minutes = 15): Builder
    {
        return $query->where('last_activity_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(username) LIKE ?', [$like])
                ->orWhere('id', '=', (int) $term);
        });
    }

    // ── Статика ─────────────────────────────────────────────────────────

    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    /** Присваивает роль и синхронизирует флаг is_staff. */
    public function assignRole(string $role): void
    {
        $this->role = $role;
        $this->is_staff = $this->roleLevel() >= self::ROLE_LEVELS[self::ROLE_SUPPORT];
        $this->save();
    }

    public function markLogin(?string $ip = null): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'last_activity_at' => now(),
            'login_count' => $this->login_count + 1,
        ])->save();
    }

    public function touchActivity(): void
    {
        // Не пишем в БД на каждом запросе — достаточно раз в 5 минут
        if ($this->last_activity_at === null || $this->last_activity_at->lt(now()->subMinutes(5))) {
            $this->forceFill(['last_activity_at' => now()])->saveQuietly();
        }
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed;
    }

    public function to2faPending(): void
    {
        $this->forceFill(['two_factor_2fa_pending_until' => now()])->save();
    }

    public function clear2faPending(): void
    {
        $this->forceFill(['two_factor_2fa_pending_until' => null])->save();
    }

    public function twoFactorGraceExpired(): bool
    {
        $days = (int) config('hosting.auth.two_factor.grace_days', 7);

        if ($days <= 0 || $this->hasTwoFactor()) {
            return false;
        }

        if (!in_array($this->role, (array) config('hosting.auth.two_factor.required_for_roles', []), true)) {
            return false;
        }

        return now()->diffInDays(Carbon::parse($this->created_at)) >= $days;
    }
}
