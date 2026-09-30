<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerInstallLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'step', 'name', 'status', 'output', 'progress', 'duration_ms', 'error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'step' => 'integer',
            'progress' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'done' => 'green',
            'failed' => 'red',
            'running' => 'yellow',
            default => 'gray',
        };
    }
}
