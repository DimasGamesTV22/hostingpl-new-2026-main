<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Симметричное шифрование чувствительных строк (токены нод, секреты платежей).
 * Используется APP_KEY; при ротации ключа значения нужно перешифровать.
 */
final class Crypto
{
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new \RuntimeException('Не удалось зашифровать значение.');
        }

        return base64_encode($iv.$cipher);
    }

    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    /** Маскирует значение для показа в UI: gdc_1a2b…9xyz */
    public static function mask(?string $value, int $visible = 4): string
    {
        if (blank($value)) {
            return '';
        }

        $len = mb_strlen($value);
        if ($len <= $visible * 2) {
            return str_repeat('•', $len);
        }

        return mb_substr($value, 0, $visible)
            .'…'
            .mb_substr($value, -$visible);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function randomPassword(int $length = 24): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%^*-_';
        $max = mb_strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    private static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        if (strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY должен быть не короче 32 символов.');
        }

        return mb_substr($key, 0, 32, 'UTF-8');
    }
}
