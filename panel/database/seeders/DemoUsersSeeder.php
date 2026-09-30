<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-аккаунты.
 *
 * Пароли указаны открытым текстом только здесь — это заготовка для локального
 * стенда. В модели User нет cast `hashed`, поэтому хэшируем явно.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'email' => 'admin@example.com',
                'name' => 'Администратор',
                'password' => 'admin12345',
                'role' => User::ROLE_SUPERADMIN,
                'is_staff' => true,
                'is_demo' => false,
                'balance' => 0,
            ],
            [
                'email' => 'demo@example.com',
                'name' => 'Демо-клиент',
                'password' => 'demo12345',
                'role' => User::ROLE_USER,
                'is_staff' => false,
                'is_demo' => true,
                'balance' => 500,
            ],
        ];

        foreach ($accounts as $account) {
            $password = $account['password'];

            User::updateOrCreate(
                ['email' => $account['email']],
                array_merge($account, [
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ])
            );
        }
    }
}
