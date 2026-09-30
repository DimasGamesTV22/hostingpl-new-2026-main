<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Настройки. Ключи повторяют структуру config/hosting.php, поэтому
         * админ может переопределить любое значение без правки файлов.
         */
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40)->index();
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|bool|int|float|json|password
            $table->boolean('is_public')->default(false);  // отдавать без авторизации
            $table->string('label', 190)->nullable();
            $table->string('hint', 512)->nullable();
            $table->string('validation', 512)->nullable();
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name', 190)->nullable();
            $table->string('actor_role', 24)->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 512);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 190);
            $table->string('summary', 512)->nullable();
            $table->longText('body');
            $table->string('image')->nullable();
            $table->string('category', 40)->default('general');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 190);
            $table->longText('body');
            $table->json('meta')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 512);
            $table->text('answer');
            $table->string('category', 40)->default('general');
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
