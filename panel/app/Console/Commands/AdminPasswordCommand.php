<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Утилиты администрирования, нужные установщику и при ручной настройке.
 */
class AdminPasswordCommand extends Command
{
    protected $signature = 'gamedock:admin-password
        {--email=admin@example.com : Email администратора}
        {--password= : Новый пароль (если не указан — генерируется)}
        {--name=Администратор : Имя}
        {--show : Показать сгенерированный пароль}';

    protected $description = 'Создаёт или обновляет администратора панели';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->option('email')));
        $password = (string) ($this->option('password') ?: bin2hex(random_bytes(8)));
        $name = (string) $this->option('name');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'status' => User::STATUS_ACTIVE,
                'block_reason' => null,
            ])->save();

            $user->assignRole(User::ROLE_SUPERADMIN);

            $this->info("Пароль администратора {$email} обновлён.");

            if ($this->option('show')) {
                $this->line("Пароль: {$password}");
            }

            return self::SUCCESS;
        }

        $user = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $name,
            'username' => 'admin',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'referral_code' => User::generateReferralCode(),
        ]);

        $user->assignRole(User::ROLE_SUPERADMIN);

        $this->info("Администратор {$email} создан.");

        if ($this->option('show')) {
            $this->line("Пароль: {$password}");
        }

        return self::SUCCESS;
    }
}
