<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Кошелёк: единый реестр операций ────────────────────────────────
        Schema::create('user_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->index();
            // deposit (+) | withdraw (-) | purchase (-) | refund (+) | bonus (+)
            // | referral (+) | adjustment (+/-)
            $table->string('direction', 4);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status', 16)->default('completed'); // pending|completed|failed|refunded

            $table->string('source', 32)->default('system');   // yookassa|cryptobot|admin|wallet|schedule
            $table->string('reference', 190)->nullable()->index(); // внешний id
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // ── Пополнения (платёжные интенты) ────────────────────────────────
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('method', 24)->index();   // wallet|yookassa|tinkoff|cryptobot|manual
            $table->string('status', 16)->default('pending'); // pending|processing|paid|failed|expired|refunded
            $table->string('invoice_url', 1024)->nullable();
            $table->string('provider_id', 190)->nullable()->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['user_id', 'status']);
        });

        // ── Начисления за серверы (подписки) ──────────────────────────────
        Schema::create('server_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('user_transactions')->nullOnDelete();
            $table->string('type', 32)->default('period'); // period|extra_slots|port|upgrade|manual
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status', 16)->default('pending'); // pending|paid|failed|waived
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'period_start']);
            $table->index(['status', 'period_end']);
        });

        // ── Заказы в магазине доп. услуг ───────────────────────────────────
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('product', 32)->index();   // extra_slots|extra_memory|extra_disk|extra_cpu|port|backup|support
            $table->string('label', 120);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 16)->default('шт');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('status', 16)->default('pending'); // pending|paid|applied|failed|cancelled
            $table->foreignId('transaction_id')->nullable()->constrained('user_transactions')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // ── Исходящие вебхуки ─────────────────────────────────────────────
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('url', 1024);
            $table->string('secret', 64);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error', 512)->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64)->index();
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error', 512)->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('server_charges');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('user_transactions');
    }
};
