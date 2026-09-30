<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Каталог игр. Полностью редактируется из админки — можно добавить свою игру
         * без правки кода: указать образ/рантайм, команду запуска, скрипт установки,
         * тип query, шаблоны конфигов и файлов.
         */
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('family', 40)->index();   // minecraft | cs2 | gta | mta | survival | custom
            $table->text('description')->nullable();
            $table->text('short_description', 300)->nullable();
            $table->string('icon')->nullable();
            $table->string('banner')->nullable();
            $table->string('trailer_url')->nullable();
            $table->json('tags')->nullable();

            // ── Запуск ──────────────────────────────────────────────────────
            // startup: {
            //   exec: "java", args: ["-Xms512M","-Xmx1024M","jar","server.jar"],
            //   cwd: ".", env: {...}, user: "gamedock",
            //   stop_signal: "SIGTERM", stop_timeout: 30,
            //   query: { type: "minecraft"|"valve"|"samp"|"mta"|"rust"|"none", port_from: "query_port" },
            //   rcon: { type: "minecraft"|"source"|"samp", port_from: "rcon_port" },
            //   healthcheck: { type: "query", interval: 30 }
            // }
            $table->json('startup');

            // installer: {
            //   type: "steamcmd"|"script"|"download"|"none",
            //   app_id: 380, script: "install.sh", source_url: "...",
            //   commands: [...], timeout: 1800
            // }
            $table->json('installer');

            // Файлы, которые нужно сгенерировать/скопировать при первом запуске
            $table->json('bootstrap_files')->nullable();

            // Файлы, в которые можно писать настройки через панель (редактор)
            $table->json('config_files')->nullable(); // [{path, format, fields:[{key,type,label,...}]}]

            // ── Рантайм ─────────────────────────────────────────────────────
            $table->string('image', 190)->nullable();              // docker/podman image
            $table->json('runtime_overrides')->nullable();          // переопределения рантайма
            $table->string('working_user', 64)->default('gamedock');
            $table->json('default_env')->nullable();

            // ── Steam ───────────────────────────────────────────────────────
            $table->boolean('uses_steamcmd')->default(false)->index();
            $table->unsignedInteger('steam_appid')->nullable();
            $table->unsignedInteger('default_branch')->nullable();

            // ── Слоты и цена ────────────────────────────────────────────────
            $table->unsignedSmallInteger('min_slots')->default(1);
            $table->unsignedSmallInteger('max_slots')->default(1000);
            $table->unsignedSmallInteger('default_slots')->default(10);
            $table->unsignedSmallInteger('slot_step')->default(1);
            $table->decimal('price_per_slot_month', 10, 2)->default(0);

            // ── Ресурсы по умолчанию ────────────────────────────────────────
            $table->unsignedInteger('default_memory_mb')->default(1024);
            $table->unsignedInteger('min_memory_mb')->default(512);
            $table->unsignedInteger('default_cpu_percent')->default(50);
            $table->unsignedInteger('default_disk_mb')->default(10240);

            // ── Возможности ─────────────────────────────────────────────────
            $table->boolean('supports_rcon')->default(false);
            $table->boolean('supports_query')->default(false);
            $table->boolean('supports_bedrock')->default(false);
            $table->boolean('supports_plugins')->default(false);
            $table->boolean('supports_auto_update')->default(true);
            $table->boolean('supports_custom_builds')->default(false);
            $table->json('builds')->nullable();   // список доступных сборок/версий
            $table->boolean('supports_cron')->default(true);

            // ── Служебное ───────────────────────────────────────────────────
            $table->boolean('is_custom')->default(false);   // добавлена через админку
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'is_public', 'sort']);
        });

        /*
         * Плагины / моды / готовые сборки для установки в один клик.
         * source_type: url (качать по ссылке) | builtin (файл из репозитория game-images) | s3
         */
        Schema::create('game_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('name');
            $table->string('type', 24)->default('plugin'); // plugin | mod | script | config | build | datapack
            $table->text('description')->nullable();
            $table->string('version', 40)->nullable();
            $table->string('game_version', 60)->nullable();

            $table->string('source_type', 16)->default('url');
            $table->string('source_url', 1024)->nullable();
            $table->string('builtin_path', 512)->nullable();
            $table->string('s3_key', 512)->nullable();

            // Куда распаковать: plugins/ | mods/ | server.cfg | ...
            $table->string('target_path', 512)->nullable();
            $table->json('env_replace')->nullable();   // [[file, search, replace], ...]
            $table->json('post_commands')->nullable(); // команды в консоль после установки
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->json('checksum_urls')->nullable(); // [minecraft:[url], bukkit:[url]]

            $table->boolean('is_official')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('installs_count')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'slug']);
        });

        Schema::create('game_template_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | downloading | installed | failed | removed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_template_installs');
        Schema::dropIfExists('game_templates');
        Schema::dropIfExists('games');
    }
};
