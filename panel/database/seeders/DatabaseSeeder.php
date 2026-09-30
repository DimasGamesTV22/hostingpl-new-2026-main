<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Точка входа для `php artisan db:seed`.
 *
 * Основные справочники (настройки, каталог игр, тарифы, отделения поддержки)
 * заполняются миграцией 2026_01_01_001200_seed_core_data, поэтому сюда попадает
 * только то, что нужно для ручного прогона и демо-стенда.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoUsersSeeder::class,
            DemoCatalogueSeeder::class,
        ]);
    }
}
