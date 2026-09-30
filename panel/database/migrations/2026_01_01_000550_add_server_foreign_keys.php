<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Внешние ключи на servers, отложенные до появления самой таблицы.
 *
 * Таблица game_template_installs создаётся в 000200, а resource_allocations —
 * в 000300, тогда как servers появляется только в 000500. Описать ключ прямо
 * в этих миграциях нельзя: MySQL отвечает ошибкой 150 «Foreign key constraint
 * is incorrectly formed» и вся миграция падает. Поэтому здесь колонки уже
 * есть, а ограничения добавляются после того, как servers существует.
 */
return new class extends Migration
{
    /**
     * Таблица и колонка, на которые вешается ключ.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const KEYS = [
        // server_id у game_template_installs объявлен обязательным
        ['game_template_installs', 'server_id'],
        // server_id у resource_allocations объявлен nullable
        ['resource_allocations', 'server_id'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Именно foreign(), а не foreignId(): колонка уже создана в
            // 000200/000300, и foreignId() пытался бы добавить её заново —
            // «Duplicate column name 'server_id'».
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)
                    ->references('id')
                    ->on('servers')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        }
    }
};
