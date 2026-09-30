<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('username', 40)->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Роль: user | support | moderator | admin | superadmin
            $table->string('role', 20)->default('user')->index();
            $table->boolean('is_staff')->default(false)->index();

            // Кошелёк
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->decimal('total_deposited', 14, 2)->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->timestamp('balance_updated_at')->nullable();

            // Рефералка
            $table->string('referral_code', 16)->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('referral_count')->default(0);

            // Авторизация
            $table->boolean('two_factor_enabled')->default(false);
            $table->boolean('two_factor_confirmed')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            $table->timestamp('two_factor_2fa_pending_until')->nullable();

            $table->boolean('terms_accepted_at')->nullable();
            $table->boolean('newsletter')->default(false);

            // Ограничения
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->decimal('discount_percent', 5, 2)->default(0);

            // Профиль
            $table->string('locale', 5)->default('ru');
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->string('avatar_url')->nullable();
            $table->text('about')->nullable();
            $table->text('note')->nullable();          // внутренняя заметка администратора
            $table->string('contact_email')->nullable();
            $table->string('contact_telegram')->nullable();

            // Безопасность и аудит
            $table->string('status', 20)->default('active')->index(); // active | blocked
            $table->string('block_reason')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->string('register_ip', 45)->nullable()->index();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('login_count')->default(0);
            $table->timestamp('password_changed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'status']);
            $table->index('last_activity_at');
        });

        Schema::create('user_oauth_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id', 128);
            $table->string('nickname')->nullable();
            $table->string('avatar')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });

        // Активные сессии пользователя («выйти со всех устройств»)
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device', 64)->nullable();
            $table->string('location', 128)->nullable();
            $table->boolean('remember')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // Защита от брутфорса
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->index();
            $table->string('email', 190)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_hash', 64);
            $table->boolean('successful')->default(false);
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->index(['ip_hash', 'created_at']);
        });

        Schema::create('ip_bans', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 64)->unique();
            $table->string('reason', 255);
            $table->enum('scope', ['login', 'api', 'all'])->default('all');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 190)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });

        // Одноразовые коды (подтверждение email, 2FA по почте, вход с нового устройства)
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->string('purpose', 32); // verify_email | login_2fa | device_login | password_reset
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });

        // Персональные токены для внешних интеграций (CI/CD, скрипты)
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_prefix', 12);
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->json('ip_whitelist')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48)->index();
            $table->string('title');
            $table->string('body', 1024)->nullable();
            $table->string('link')->nullable();
            $table->string('level', 16)->default('info'); // info | success | warning | danger
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('ip_bans');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_oauth_accounts');
        Schema::dropIfExists('users');
    }
};
