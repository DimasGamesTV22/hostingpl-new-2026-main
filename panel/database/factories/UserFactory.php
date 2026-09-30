<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Database\Factories\Concerns\ForceFillsAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use ForceFillsAttributes;

    protected $model = User::class;

    /** Статический хэш — bcrypt считается один раз на весь прогон. */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_USER,
            'is_staff' => false,
            'balance' => 0,
            'currency' => 'RUB',
            'locale' => 'ru',
            'timezone' => 'Europe/Moscow',
            'status' => User::STATUS_ACTIVE,
            'newsletter' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['email_verified_at' => now()]);
    }

    public function withBalance(float|int|string $amount): static
    {
        return $this->state(fn () => ['balance' => $amount]);
    }

    public function onTrial(): static
    {
        return $this->state(fn () => [
            'trial_ends_at' => now()->addDays(3),
            'is_demo' => false,
        ]);
    }

    public function demo(): static
    {
        return $this->state(fn () => [
            'is_demo' => true,
            'balance' => 500,
        ]);
    }

    public function blocked(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => User::STATUS_BLOCKED,
            'block_reason' => $reason ?? 'Нарушение правил',
            'blocked_at' => now(),
        ]);
    }

    public function staff(string $role = User::ROLE_SUPPORT): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'is_staff' => true,
        ]);
    }

    public function admin(): static
    {
        return $this->staff(User::ROLE_ADMIN)->verified();
    }

    public function superadmin(): static
    {
        return $this->staff(User::ROLE_SUPERADMIN)->verified();
    }

    /** Пользователь с включённым и подтверждённым 2FA (секрет — фиктивный base32). */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_enabled' => true,
            'two_factor_confirmed' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_enabled_at' => now(),
        ]);
    }

    /** Ссылка-приглашение конкретного реферера. */
    public function referredBy(User $referrer): static
    {
        return $this->state(fn () => ['referred_by' => $referrer->id]);
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn () => ['email' => $email]);
    }

    public function withName(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    public function withReferralCode(string $code): static
    {
        return $this->state(fn () => ['referral_code' => Str::upper($code)]);
    }
}
