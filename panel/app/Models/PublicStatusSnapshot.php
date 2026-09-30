<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicStatusSnapshot extends Model
{
    protected $fillable = [
        'server_id', 'node_id', 'game_id', 'name', 'address',
        'slots', 'players', 'uptime_seconds', 'status', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'uptime_seconds' => 'integer',
            'players' => 'integer',
            'slots' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
