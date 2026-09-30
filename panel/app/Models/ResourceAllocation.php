<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'node_id', 'server_id', 'kind', 'port', 'is_primary', 'is_reserved', 'label', 'price',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_primary' => 'boolean',
            'is_reserved' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isFree(): bool
    {
        return $this->server_id === null || $this->is_reserved;
    }
}
