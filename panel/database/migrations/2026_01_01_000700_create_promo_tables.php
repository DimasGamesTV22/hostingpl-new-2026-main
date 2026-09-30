<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120)->nullable();

            // discount — скидка на сумму оплаты (%)
            // duration — добавить дни к аренде
            // bonus    — разовый бонус (деньги / слоты / RAM)
            $table->enum('type', ['discount', 'duration', 'bonus'])->index();

            $table->unsignedTinyInteger('percent')->nullable();   // для discount
            $table->decimal('amount', 12, 2)->nullable();          // фиксированная скидка, для discount
            $table->unsignedSmallInteger('days')->nullable();      // для duration
            $table->decimal('bonus_rub', 12, 2)->nullable();      // для bonus
            $table->unsignedSmallInteger('bonus_slots')->nullable();
            $table->unsignedInteger('bonus_memory_mb')->nullable();
            $table->unsignedInteger('bonus_disk_mb')->nullable();
            $table->unsignedSmallInteger('bonus_days')->nullable();

            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedSmallInteger('per_user_limit')->default(1);
            $table->decimal('min_order', 10, 2)->default(0);
            $table->decimal('max_discount', 10, 2)->nullable();     // макс. размер скидки в рублях

            $table->json('applies_to_tariffs')->nullable();  // пусто/null = на все
            $table->json('applies_to_games')->nullable();
            $table->json('applies_to_products')->nullable();
            $table->boolean('first_payment_only')->default(false);
            $table->boolean('allow_stacking')->default(false);

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained('deposits')->nullOnDelete();
            $table->decimal('discount', 12, 2)->default(0);
            $table->unsignedSmallInteger('days_added')->default(0);
            $table->decimal('bonus_applied', 12, 2)->default(0);
            $table->unsignedSmallInteger('slots_added')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['promo_code_id', 'user_id']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending|completed|rejected
            $table->decimal('reward_referrer', 12, 2)->default(0);
            $table->decimal('reward_referred', 12, 2)->default(0);
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->string('trigger', 32)->default('first_payment'); // registration|first_payment|manual
            $table->string('rejected_reason', 190)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['referrer_id', 'referred_id']);
            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('promo_uses');
        Schema::dropIfExists('promo_codes');
    }
};
