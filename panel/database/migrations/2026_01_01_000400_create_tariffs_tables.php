<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->text('short_description', 300)->nullable();

            // Модель тарификации
            // package — фикс-пакет ресурсов
            // slots   — цена считается по количеству слотов
            // hybrid  — базовый пакет + доп. ресурсы по цене
            $table->enum('model', ['package', 'slots', 'hybrid'])->default('package');

            $table->decimal('price', 10, 2)->default(0);
            $table->enum('billing_period', ['day', 'month', 'week', 'year'])->default('month');
            $table->unsignedSmallInteger('duration_days')->default(30);

            // Включено в тариф
            $table->unsignedSmallInteger('slots')->default(10);
            $table->unsignedSmallInteger('extra_slots')->default(0);
            $table->unsignedInteger('memory_mb')->default(1024);
            $table->unsignedInteger('extra_memory_mb')->default(0);
            $table->unsignedInteger('cpu_percent')->default(50);
            $table->unsignedInteger('extra_cpu_percent')->default(0);
            $table->unsignedBigInteger('disk_mb')->default(10240);
            $table->unsignedBigInteger('extra_disk_mb')->default(0);
            $table->unsignedInteger('network_mbps')->default(25);
            $table->unsignedInteger('pids')->default(512);

            $table->unsignedSmallInteger('backups')->default(3);
            $table->unsignedSmallInteger('max_servers')->default(1);
            $table->boolean('allow_sub_accounts')->default(true);
            $table->boolean('allow_custom_port')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->boolean('allow_console')->default(true);
            $table->boolean('allow_scheduler')->default(true);
            $table->boolean('allow_file_manager')->default(true);
            $table->boolean('allow_rcon')->default(false);
            $table->boolean('private')->default(false);

            $table->json('features')->nullable();   // [{icon, text}] для лендинга
            $table->string('badge', 32)->nullable();
            $table->string('accent', 16)->nullable();

            $table->boolean('is_trial')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_popular')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Цены на докупаемые ресурсы и услуги внутри тарифа (модель hybrid)
        Schema::create('tariff_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 32); // extra_slots|extra_memory|extra_disk|extra_cpu|port|backup|support
            $table->string('label', 120);
            $table->string('unit', 16)->default('шт');
            $table->unsignedInteger('unit_quantity')->default(1); // за сколько единиц цена
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('min_quantity')->default(0);
            $table->unsignedInteger('max_quantity')->default(0);
            $table->boolean('is_recurring')->default(true);  // повторно списывать каждый период
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tariff_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_prices');
        Schema::dropIfExists('tariffs');
    }
};
