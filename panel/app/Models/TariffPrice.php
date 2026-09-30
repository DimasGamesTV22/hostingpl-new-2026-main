<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TariffPrice extends Model
{
    use HasFactory;

    public const RESOURCE_EXTRA_SLOTS = 'extra_slots';
    public const RESOURCE_EXTRA_MEMORY = 'extra_memory';
    public const RESOURCE_EXTRA_DISK = 'extra_disk';
    public const RESOURCE_EXTRA_CPU = 'extra_cpu';
    public const RESOURCE_PORT = 'port';
    public const RESOURCE_BACKUP = 'backup';
    public const RESOURCE_SUPPORT = 'support';

    protected $fillable = [
        'tariff_id', 'resource', 'label', 'unit', 'unit_quantity', 'price',
        'min_quantity', 'max_quantity', 'is_recurring', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_recurring' => 'boolean',
            'unit_quantity' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function unitLabel(): string
    {
        return $this->unit_quantity > 1
            ? number_format($this->unit_quantity / 1024, 0).' '.$this->unit
            : $this->unit;
    }

    public function pricePerUnit(float $quantity = 1): float
    {
        $units = max(1, (int) ceil($quantity / max(1, $this->unit_quantity)));

        return round($units * (float) $this->price, 2);
    }
}
