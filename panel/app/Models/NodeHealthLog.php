<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeHealthLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'node_id', 'cpu_percent', 'memory_used_mb', 'memory_total_mb',
        'disk_used_mb', 'disk_total_mb', 'network_in_mbps', 'network_out_mbps',
        'running_servers', 'load_1', 'latency_ms', 'is_online', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'is_online' => 'boolean',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
