<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecretCodeUse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'secret_code_id', 'server_id', 'user_id', 'player_id', 'player_name',
        'ip', 'reward_applied', 'announced', 'response', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_applied' => 'array',
            'announced' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function secretCode(): BelongsTo
    {
        return $this->belongsTo(SecretCode::class, 'secret_code_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
