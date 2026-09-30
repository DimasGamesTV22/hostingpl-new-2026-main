<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 80);
            $table->string('external_id', 128)->nullable(); // id контейнера/LXC/юнита на ноде

            // pending → installing → installed → starting → running → stopping → stopped
            // + crashed | error | suspended | deleting | deleted
            $table->string('status', 24)->default('pending')->index();
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('status_changed_at')->nullable();

            // Снимок рантайма и конфигурации запуска на момент создания
            $table->string('runtime', 16)->nullable();
            $table->json('startup')->nullable();     // распарсенный startup игры + переопределения
            $table->json('env')->nullable();
            $table->json('config_values')->nullable();// значения, заданные через панель (config_files)
            $table->string('install_command', 1024)->nullable();
            $table->string('build_version', 64)->nullable();

            // Порты
            $table->unsignedInteger('game_port')->nullable();
            $table->unsignedInteger('query_port')->nullable();
            $table->unsignedInteger('rcon_port')->nullable();
            $table->string('address', 190)->nullable();      // ip:port для игроков
            $table->string('query_address', 190)->nullable();
            $table->unsignedInteger('rcon_password_length')->default(24);

            // Ресурсы (квоты)
            $table->unsignedInteger('memory_mb')->default(1024);
            $table->unsignedInteger('cpu_percent')->default(50);
            $table->unsignedInteger('swap_mb')->default(0);
            $table->unsignedBigInteger('disk_mb')->default(10240);
            $table->unsignedInteger('disk_used_mb')->default(0);
            $table->unsignedInteger('network_mbps')->default(25);
            $table->unsignedInteger('pids')->default(512);
            $table->unsignedSmallInteger('slots')->default(10);
            $table->unsignedSmallInteger('base_slots')->default(10);

            // Установка
            $table->unsignedTinyInteger('install_progress')->default(0);
            $table->text('install_log')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_install_attempt_at')->nullable();
            $table->unsignedTinyInteger('install_attempts')->default(0);
            $table->json('install_manifest')->nullable();   // список файлов, созданных при установке

            // Эксплуатация
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_stopped_at')->nullable();
            $table->timestamp('last_crash_at')->nullable();
            $table->unsignedInteger('crash_count')->default(0);
            $table->unsignedInteger('restart_count')->default(0);
            $table->boolean('watchdog_enabled')->default(true);
            $table->boolean('sub_accounts_enabled')->default(true);
            $table->boolean('is_frozen')->default(false);   // заморожен за неоплату, но не удалён
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();
            $table->timestamp('auto_stop_at')->nullable();
            $table->timestamp('purge_at')->nullable();

            // Метрики (денормализовано для быстрых списков)
            $table->unsignedTinyInteger('players_online')->default(0);
            $table->decimal('cpu_usage', 5, 2)->default(0);
            $table->unsignedBigInteger('memory_usage_mb')->default(0);
            $table->unsignedBigInteger('network_in_mbps')->default(0);
            $table->unsignedBigInteger('network_out_mbps')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->timestamp('metrics_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['node_id', 'status']);
            $table->index('expires_at');
            $table->index('address');
        });

        // Саб-аккаунты сервера (второй режим: можно включать/выключать индивидуально)
        Schema::create('server_sub_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('name', 120);
            $table->enum('role', ['owner', 'admin', 'operator', 'viewer'])->default('operator');
            $table->json('permissions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consoles_today')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['server_id', 'email']);
        });

        // Журнал событий сервера
        Schema::create('server_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();   // power|install|backup|file|setting|console|sub_account|schedule|error
            $table->string('level', 12)->default('info'); // debug|info|warning|error
            $table->string('title', 190);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        // Журнал установки (пошаговый прогресс)
        Schema::create('server_install_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->string('name', 120);
            $table->string('status', 16)->default('running'); // running|done|failed|skipped
            $table->text('output')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'step']);
        });

        // Бэкапы / снапшоты
        Schema::create('server_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 16)->default('manual'); // auto|manual|pre_upgrade|system
            $table->string('schedule', 32)->nullable();      // hourly|daily|weekly|monthly
            $table->string('status', 16)->default('pending'); // pending|running|done|failed
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path', 512)->nullable();          // путь на ноде
            $table->string('s3_key', 512)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->boolean('is_locked')->default(false);     // не удалять при ротации
            $table->boolean('is_uploaded')->default(false);
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index(['server_id', 'schedule', 'created_at']);
        });

        // Cron-планировщик игрового сервера
        Schema::create('server_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('expression', 120);              // cron
            $table->string('job_type', 24);                // command|restart|start|stop|backup|update|webhook
            $table->json('payload');
            $table->boolean('is_active')->default(true);
            $table->boolean('run_on_stopped')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output', 2048)->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
        });

        // Очередь команд, отправленных агенту
        Schema::create('server_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);           // power.start|power.stop|power.restart|power.kill|console.write|files.*|...
            $table->json('payload');
            $table->string('status', 16)->default('queued'); // queued|sent|ack|done|failed|timeout
            $table->unsignedTinyInteger('priority')->default(5);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_commands');
        Schema::dropIfExists('server_schedules');
        Schema::dropIfExists('server_snapshots');
        Schema::dropIfExists('server_install_logs');
        Schema::dropIfExists('server_events');
        Schema::dropIfExists('server_sub_accounts');
        Schema::dropIfExists('servers');
    }
};
