<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id', 'user_id', 'type', 'level', 'title', 'message', 'context', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
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

    public function levelColor(): string
    {
        return match ($this->level) {
            'error' => 'red',
            'warning' => 'yellow',
            'success' => 'green',
            default => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this->type) {
            'power' => 'power',
            'install' => 'download',
            'backup' => 'archive',
            'file' => 'file',
            'setting' => 'cog',
            'console' => 'terminal',
            'sub_account' => 'users',
            'schedule' => 'clock',
            'error' => 'alert-triangle',
            default => 'info',
        };
    }
}
