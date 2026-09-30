<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Записывает привилегированные действия в audit_logs.
 * Никогда не бросает исключений: ошибка аудита не должна ломать операцию,
 * но попадает в основной лог.
 */
class Auditor
{
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
    ): void {
        try {
            $user = Auth::user();

            AuditLog::create([
                'user_id' => $user?->id,
                'actor_name' => $user?->name ?? 'system',
                'actor_role' => $user?->role ?? 'system',
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'description' => mb_substr($description, 0, 500),
                'old_values' => $old ?: null,
                'new_values' => $new ?: null,
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Не удалось записать аудит: '.$e->getMessage(), [
                'action' => $action,
                'exception' => $e,
            ]);
        }
    }

    /** Логирует изменение модели: снимает diff по отслеживаемым полям. */
    public static function updated(Model $model, string $action, array $watch = []): void
    {
        $old = $model->getOriginal($watch ?: null);
        $new = $model->getAttributes();

        if ($watch) {
            $old = array_intersect_key($old, array_flip($watch));
            $new = array_intersect_key($new, array_flip($watch));
        }

        $changed = [];
        foreach ($new as $key => $value) {
            if (($old[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $old[$key] ?? null, 'to' => $value];
            }
        }

        if ($changed === []) {
            return;
        }

        self::log(
            $action,
            sprintf('Изменён %s #%s: %s', class_basename($model), $model->getKey(), implode(', ', array_keys($changed))),
            $model,
            ['changed' => $changed],
        );
    }

    public static function login(User $user, Request $request, bool $success = true): void
    {
        self::log(
            $success ? 'auth.login' : 'auth.login_failed',
            $success ? 'Вход в панель' : 'Неудачная попытка входа',
            $user,
            ['email' => $user->email, 'ip' => $request->ip()],
        );
    }

    public static function impersonate(User $target): void
    {
        $admin = Auth::user();
        if (! $admin) {
            return;
        }

        self::log(
            'admin.impersonate',
            sprintf('%s вошёл как %s (#%d)', $admin->name, $target->name, $target->id),
            $target,
        );
    }
}
