<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ServerCommand extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACK = 'ack';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';

    public const TYPE_POWER_START = 'power.start';
    public const TYPE_POWER_STOP = 'power.stop';
    public const TYPE_POWER_RESTART = 'power.restart';
    public const TYPE_POWER_KILL = 'power.kill';
    public const TYPE_CONSOLE_WRITE = 'console.write';
    public const TYPE_CONSOLE_READ = 'console.read';

    protected $fillable = [
        'server_id', 'user_id', 'type', 'payload', 'status', 'priority', 'result', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $command) {
            $command->uuid ??= (string) Str::uuid();
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_SENT]);
    }

    public function scopeForServer(Builder $query, int $serverId): Builder
    {
        return $query->where('server_id', $serverId);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_TIMEOUT], true);
    }
}
