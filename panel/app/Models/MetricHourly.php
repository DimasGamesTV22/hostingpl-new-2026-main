<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricHourly extends Model
{
    use HasFactory;

    protected $table = 'metric_hourly';

    protected $fillable = [
        'server_id', 'node_id', 'hour', 'cpu_avg', 'cpu_max',
        'memory_avg_mb', 'memory_max_mb', 'disk_used_mb',
        'network_in_mb', 'network_out_mb', 'players_avg', 'players_max',
        'samples', 'restarts', 'downtime_seconds', 'uptime_seconds', 'uptime_percent',
    ];

    protected function casts(): array
    {
        return [
            'hour' => 'integer',
            'samples' => 'integer',
            'restarts' => 'integer',
            'cpu_avg' => 'float',
            'cpu_max' => 'float',
            'players_avg' => 'float',
            'uptime_percent' => 'float',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function hourToCarbon(): \Illuminate\Support\Carbon
    {
        return now()->setTimestamp($this->hour);
    }
}
