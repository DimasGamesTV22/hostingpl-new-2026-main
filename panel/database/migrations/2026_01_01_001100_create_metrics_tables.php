<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Агрегат метрик. Сырые точки (раз в 5 секунд) живут в Redis с TTL 7 дней,
         * здесь — поминутные/часовые агрегаты для графиков за 30+ дней.
         * Одна строка = один сервер + один час.
         */
        Schema::create('metric_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('hour');            // unixtime часа

            $table->decimal('cpu_avg', 6, 2)->default(0);
            $table->decimal('cpu_max', 6, 2)->default(0);
            $table->unsignedBigInteger('memory_avg_mb')->default(0);
            $table->unsignedBigInteger('memory_max_mb')->default(0);
            $table->unsignedBigInteger('disk_used_mb')->default(0);
            $table->unsignedBigInteger('network_in_mb')->default(0);
            $table->unsignedBigInteger('network_out_mb')->default(0);
            $table->decimal('players_avg', 8, 2)->default(0);
            $table->unsignedInteger('players_max')->default(0);
            $table->unsignedInteger('samples')->default(0);
            $table->unsignedSmallInteger('restarts')->default(0);
            $table->unsignedInteger('downtime_seconds')->default(0);
            $table->unsignedInteger('uptime_seconds')->default(0);
            $table->decimal('uptime_percent', 5, 2)->default(100);

            $table->timestamps();

            $table->unique(['server_id', 'hour']);
            $table->index(['node_id', 'hour']);
            $table->index('hour');
        });

        // Данные для публичного мониторинга (лендинг) — держим отдельно, чтобы
        // не тянуть тяжёлые таблицы серверов на публичный роут.
        Schema::create('public_status_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('address', 190);
            $table->unsignedSmallInteger('slots')->default(0);
            $table->unsignedSmallInteger('players')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->string('status', 20)->default('offline');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['status', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_status_snapshots');
        Schema::dropIfExists('metric_hourly');
    }
};
