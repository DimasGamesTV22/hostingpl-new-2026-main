<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerSubAccount extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    /** Права, доступные саб-аккаунту. Совпадают с config/hosting.php → sub_accounts.permissions */
    public const PERMISSIONS = [
        'console.read' => 'Просмотр консоли',
        'console.write' => 'Ввод команд в консоль',
        'console.control' => 'Запуск / остановка / рестарт',
        'files.read' => 'Просмотр файлов',
        'files.write' => 'Редактирование файлов',
        'files.delete' => 'Удаление файлов',
        'backup.create' => 'Создание бэкапов',
        'backup.restore' => 'Восстановление из бэкапа',
        'settings.read' => 'Просмотр настроек',
        'settings.write' => 'Изменение настроек',
    ];

    protected $fillable = [
        'server_id', 'user_id', 'email', 'name', 'role', 'permissions', 'is_active', 'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->role === self::ROLE_OWNER || $this->role === self::ROLE_ADMIN) {
            return true;
        }

        return in_array($permission, (array) $this->permissions, true);
    }

    public function roleLabel(): string
    {
        return __('servers.sub_accounts.roles.'.$this->role);
    }

    /** Права по умолчанию для роли. */
    public static function defaultPermissions(string $role): array
    {
        return match ($role) {
            self::ROLE_ADMIN => array_keys(self::PERMISSIONS),
            self::ROLE_OPERATOR => ['console.read', 'console.write', 'files.read', 'files.write', 'backup.create', 'settings.read'],
            self::ROLE_VIEWER => ['console.read', 'files.read', 'settings.read'],
            default => [],
        };
    }
}
