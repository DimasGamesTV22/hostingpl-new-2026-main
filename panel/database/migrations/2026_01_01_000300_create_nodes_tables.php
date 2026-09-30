<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();

            // ── Связь с панелью ────────────────────────────────────────────
            // Входящий режим (по умолчанию): агент сам подключается к wss://panel/agent/ws
            // и хранит здесь токен. Исходящий: панель подключается к агенту по host:port.
            $table->enum('connection_mode', ['inbound', 'outbound'])->default('inbound');
            $table->string('host', 190)->nullable();     // адрес агента (outbound)
            $table->unsignedInteger('agent_port')->default(9222);
            $table->text('token')->nullable();           // выдаётся агенту, хранится зашифрованно
            $table->string('token_hash', 64)->nullable()->index();
            $table->timestamp('token_rotated_at')->nullable();

            // TLS (для исходящего подключения панель → агент)
            $table->boolean('tls')->default(false);
            $table->text('tls_ca')->nullable();
            $table->string('tls_fingerprint', 128)->nullable();

            // ── География ──────────────────────────────────────────────────
            $table->string('country', 2)->nullable()->index();
            $table->string('city', 120)->nullable();
            $table->string('region', 64)->nullable()->index();
            $table->string('continent', 32)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('flagship', 190)->nullable();   // домен/IP для игроков
            $table->string('banner')->nullable();
            $table->text('features')->nullable();

            // ── Рантайм ────────────────────────────────────────────────────
            $table->string('runtime', 16)->default('docker'); // docker|podman|lxc|native
            $table->json('runtime_options')->nullable();        // параметры конкретного драйвера
            $table->json('runtimes_available')->nullable();     // что реально есть на ноде

            // ── Физические ресурсы (заполняются heartbeat'ом) ──────────────
            $table->unsignedInteger('cpu_cores')->nullable();
            $table->unsignedInteger('cpu_threads')->nullable();
            $table->string('cpu_model', 190)->nullable();
            $table->unsignedBigInteger('memory_total_mb')->nullable();
            $table->unsignedBigInteger('disk_total_mb')->nullable();
            $table->unsignedBigInteger('disk_free_mb')->nullable();
            $table->unsignedInteger('network_mbps')->nullable();
            $table->string('os', 120)->nullable();
            $table->string('kernel', 120)->nullable();
            $table->string('agent_version', 24)->nullable();
            $table->json('agent_info')->nullable();

            // ── Лимиты ноды (админ задаёт, сколько можно раздать) ──────────
            $table->unsignedInteger('max_servers')->default(50);
            $table->unsignedBigInteger('max_memory_mb')->nullable();      // null = не ограничивать
            $table->unsignedBigInteger('max_disk_mb')->nullable();
            $table->unsignedInteger('max_cpu_percent')->nullable();
            $table->unsignedTinyInteger('allocatable_percent')->default(85); // сколько % физики отдаём гостям
            $table->unsignedInteger('reserved_memory_mb')->default(1024); // ОС + агент + Docker

            // Текущее потребление (heartbeat)
            $table->unsignedBigInteger('used_memory_mb')->default(0);
            $table->unsignedBigInteger('used_disk_mb')->default(0);
            $table->unsignedInteger('running_servers')->default(0);
            $table->unsignedInteger('total_servers')->default(0);

            // ── Состояние ──────────────────────────────────────────────────
            $table->string('status', 20)->default('offline')->index(); // online|offline|maintenance|disabled
            $table->text('status_message')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('missed_heartbeats')->default(0);
            $table->decimal('load_1', 5, 2)->default(0);
            $table->decimal('load_5', 5, 2)->default(0);
            $table->decimal('load_15', 5, 2)->default(0);
            $table->string('inbound_ip', 45)->nullable();

            // Балансировка
            $table->unsignedSmallInteger('weight')->default(100);
            $table->unsignedSmallInteger('region_priority')->default(50);
            $table->boolean('prefer_over_region')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('allow_ssh_fallback')->default(false);
            $table->timestamps();
        });

        // Пул портов на ноде
        Schema::create('resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // game | query | rcon
            $table->unsignedInteger('port');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_reserved')->default(false);
            $table->string('label', 64)->nullable();
            $table->unsignedDecimal('price', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'port']);
            $table->index(['kind', 'is_reserved']);
        });

        // История состояния ноды (для графиков и расследований)
        Schema::create('node_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cpu_percent')->default(0);
            $table->unsignedBigInteger('memory_used_mb')->default(0);
            $table->unsignedBigInteger('memory_total_mb')->default(0);
            $table->unsignedBigInteger('disk_used_mb')->default(0);
            $table->unsignedBigInteger('disk_total_mb')->default(0);
            $table->unsignedBigInteger('network_in_mbps')->default(0);
            $table->unsignedBigInteger('network_out_mbps')->default(0);
            $table->unsignedInteger('running_servers')->default(0);
            $table->decimal('load_1', 5, 2)->default(0);
            $table->decimal('latency_ms', 8, 2)->nullable();
            $table->boolean('is_online')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_health_logs');
        Schema::dropIfExists('resource_allocations');
        Schema::dropIfExists('nodes');
    }
};
