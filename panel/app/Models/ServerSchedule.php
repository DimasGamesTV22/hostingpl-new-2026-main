<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonInterval;

class ServerSchedule extends Model
{
    use HasFactory;

    public const JOB_COMMAND = 'command';
    public const JOB_RESTART = 'restart';
    public const JOB_START = 'start';
    public const JOB_STOP = 'stop';
    public const JOB_BACKUP = 'backup';
    public const JOB_UPDATE = 'update';
    public const JOB_WEBHOOK = 'webhook';

    protected $fillable = [
        'server_id', 'created_by', 'name', 'expression', 'job_type', 'payload',
        'is_active', 'run_on_stopped', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_active' => 'boolean',
            'run_on_stopped' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $at);
    }

    public function jobLabel(): string
    {
        return __('servers.scheduler.jobs.'.$this->job_type, [], config('hosting.locale.default'));
    }

    public function commandPreview(): string
    {
        return match ($this->job_type) {
            self::JOB_COMMAND => (string) ($this->payload['command'] ?? ''),
            self::JOB_WEBHOOK => 'POST '.(string) ($this->payload['url'] ?? ''),
            self::JOB_BACKUP => 'Бэкап: '.($this->payload['name'] ?? 'авто'),
            self::JOB_UPDATE => 'Обновление: '.($this->payload['build'] ?? 'последняя'),
            self::JOB_START => 'Запуск',
            self::JOB_STOP => 'Остановка',
            self::JOB_RESTART => 'Рестарт',
            default => '',
        };
    }

    /** Следующее срабатывание по cron-выражению (упрощённый парсер 5 полей). */
    public function calculateNextRun(?Carbon $from = null): ?Carbon
    {
        $fields = preg_split('/\s+/', trim($this->expression)) ?: [];
        if (count($fields) !== 5) {
            return null;
        }

        $from = ($from ?? Carbon::now())->copy()->addMinute()->startOfMinute();
        $limit = 366 * 24 * 60; // максимум год вперёд

        for ($i = 0; $i < $limit; $i++) {
            $candidate = $from->copy()->addMinutes($i);

            if ($this->matches($candidate, $fields)) {
                return $candidate;
            }
        }

        return null;
    }

    private function matches(Carbon $t, array $fields): bool
    {
        [$minute, $hour, $dom, $month, $dow] = $fields;

        return $this->matchField($minute, $t->minute)
            && $this->matchField($hour, $t->hour)
            && $this->matchField($dom, $t->day)
            && $this->matchField($month, $t->month)
            && $this->matchField($dow, $t->dayOfWeekIso % 7);
    }

    private function matchField(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '*') {
                return true;
            }

            // */5
            if (str_starts_with($part, '*/')) {
                $step = max(1, (int) substr($part, 2));
                if ($value % $step === 0) {
                    return true;
                }

                continue;
            }

            // 5-10
            if (str_contains($part, '-')) {
                [$from, $to] = explode('-', $part, 2);
                if ($value >= (int) $from && $value <= (int) $to) {
                    return true;
                }

                continue;
            }

            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }

    public function statusColor(): string
    {
        return match ($this->last_status) {
            'done' => 'green',
            'failed' => 'red',
            'running' => 'yellow',
            default => 'gray',
        };
    }

    public function isDue(): bool
    {
        return $this->next_run_at !== null && Carbon::parse($this->next_run_at)->isPast();
    }

    public function humanInterval(): string
    {
        $examples = [
            '0 */6 * * *' => 'каждые 6 часов',
            '0 0 * * *' => 'каждый день в 00:00',
            '0 3 * * *' => 'каждый день в 03:00',
            '*/30 * * * *' => 'каждые 30 минут',
            '0 * * * *' => 'каждый час',
            '0 0 * * 0' => 'каждую неделю в воскресенье',
        ];

        return $examples[$this->expression] ?? $this->expression;
    }
}
