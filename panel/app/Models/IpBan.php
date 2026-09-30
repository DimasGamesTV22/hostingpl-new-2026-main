<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class IpBan extends Model
{
    protected $fillable = [
        'cidr', 'reason', 'scope', 'is_active', 'created_by', 'created_by_name', 'expires_at', 'hits',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'hits' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function isBanned(string $ip, string $scope = 'all'): bool
    {
        return static::findFor($ip, $scope) !== null;
    }

    public static function findFor(string $ip, string $scope = 'all'): ?self
    {
        if ($ip === '' || str_starts_with($ip, '127.') || $ip === '::1') {
            return null;
        }

        $key = 'gamedock:ipban:'.$scope.':'.$ip;
        $cached = Cache::get($key);

        if ($cached === false) {
            $found = null;

            $bans = static::effective()->get();
            foreach ($bans as $ban) {
                if ($ban->scope !== $scope && $ban->scope !== 'all') {
                    continue;
                }

                if (self::ipInCidr($ip, $ban->cidr)) {
                    $found = $ban;
                    break;
                }
            }

            Cache::put($key, $found, now()->addMinutes(10));

            return $found;
        }

        return $cached;
    }

    public static function flushCache(?string $ip = null): void
    {
        if ($ip !== null) {
            foreach (['all', 'login', 'api'] as $scope) {
                Cache::forget('gamedock:ipban:'.$scope.':'.$ip);
            }
        }
    }

    /** Проверяет, входит ли IP в подсеть (поддерживает IPv4 и IPv6). */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return $ip === $subnet;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    public function scopeLabel(): string
    {
        return $this->scope === 'all' ? __('admin.ip_bans.all') : __('admin.ip_bans.'.$this->scope);
    }
}
