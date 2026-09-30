<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Tariff;
use Illuminate\Database\Seeder;

/**
 * Пара площадок и тарифов, чтобы на свежей установке сразу можно было
 * создать сервер, не заходя в админку.
 */
class DemoCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        Game::firstOrCreate(
            ['slug' => 'minecraft-java'],
            ['name' => 'Minecraft Java Edition', 'family' => 'minecraft'],
        );

        $tariffs = [
            ['slug' => 'start', 'name' => 'Start', 'price' => 150, 'slots' => 10, 'memory_mb' => 2048],
            ['slug' => 'pro', 'name' => 'Pro', 'price' => 400, 'slots' => 40, 'memory_mb' => 6144],
        ];

        foreach ($tariffs as $tariff) {
            Tariff::firstOrCreate(['slug' => $tariff['slug']], $tariff);
        }
    }
}
