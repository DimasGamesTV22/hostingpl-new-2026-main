<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('email', 190)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            // Таблица называется ticket_departments. Без явного имени
            // constrained() вывел бы из колонки «departments» — таблицы,
            // которой в схеме нет, и внешний ключ не создался бы.
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 190);
            $table->string('category', 64)->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
            $table->enum('status', ['open', 'pending', 'answered', 'closed'])->default('open')->index();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_by_staff')->default(0);
            $table->unsignedInteger('unread_by_user')->default(0);
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('last_reply_by_staff')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->unsignedInteger('reply_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->string('author_name', 120);
            $table->text('body');
            $table->json('attachments')->nullable();  // [{name, path, size, disk}]
            $table->boolean('is_internal_note')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_departments');
    }
};
