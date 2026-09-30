<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TicketDepartment extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'email', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $d) {
            if (blank($d->slug)) {
                $d->slug = Str::slug($d->name);
            }
        });
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'department_id');
    }

    public function openCount(): int
    {
        return $this->tickets()->open()->count();
    }
}
