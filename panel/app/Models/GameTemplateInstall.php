<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameTemplateInstall extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_template_id', 'server_id', 'user_id', 'status', 'progress', 'error', 'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'progress' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GameTemplate::class, 'game_template_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
