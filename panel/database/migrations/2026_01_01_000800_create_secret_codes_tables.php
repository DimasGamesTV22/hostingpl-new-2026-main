<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Секретные коды. Два режима (config/hosting.php → marketing.secret_codes):
         *  1) allow_in_game_chat  — игрок пишет код в чат сервера, агент перехватывает строку
         *     и отправляет панели, панель начисляет бонус;
         *  2) allow_personal_codes — любой пользователь может создать свои коды
         *     (например, промокоды для своего клана) и раздать друзьям.
         */
        Schema::create('secret_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('hint', 190)->nullable();     // подсказка, не раскрывающая код
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();

            // server_id — код действует только на этом сервере
            // null + user_id — глобальный код пользователя (личные промокоды)
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // money (₽) | slots | days | memory | credit (внутренний счёт)
            $table->enum('reward_type', ['money', 'slots', 'days', 'memory', 'credit'])->default('money');
            $table->decimal('reward_value', 12, 2)->default(0);
            $table->unsignedSmallInteger('reward_days')->default(0);
            $table->unsignedInteger('reward_memory_mb')->default(0);

            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_player_limit')->default(1);
            $table->unsignedSmallInteger('min_rank_level')->default(0); // для SAMP/MTA — минимальный уровень
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'code']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('secret_code_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secret_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('player_id', 64)->nullable();      // steamid / qport / ник
            $table->string('player_name', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('reward_applied');
            $table->boolean('announced')->default(true);     // показать игроку в чат
            $table->text('response', 512)->nullable();        // текст, отправленный в игру
            $table->timestamp('created_at')->useCurrent();

            $table->index(['secret_code_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_code_uses');
        Schema::dropIfExists('secret_codes');
    }
};
