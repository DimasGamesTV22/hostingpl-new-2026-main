<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Реализация TOTP (RFC 6238) без внешних зависимостей.
 * Совместима с Google Authenticator, Authy, Aegis, Bitwarden.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const BASE32_PAD = '=';

    /** Сгенерировать новый секрет (base32, 32 символа = 160 бит). */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $bytes = random_bytes($length);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $secret;
    }

    /** Проверить код. Допуск по умолчанию ±1 окно (30 секунд). */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        $timestamp = time();
        $counter = intdiv($timestamp, 30);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);

        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function currentCode(string $secret): string
    {
        return $this->codeAt($secret, intdiv(time(), 30));
    }

    /** Ссылка для QR-кода (otpauth://). */
    public function otpauthUrl(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    /** Резервные коды восстановления. */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtolower(bin2hex(random_bytes(5)));
        }

        return $codes;
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    public function base32Decode(string $input): string
    {
        $input = rtrim(strtoupper($input), self::BASE32_PAD);
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $value = strpos(self::ALPHABET, $input[$i]);
            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    public function base32Encode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($input[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= self::ALPHABET[($buffer >> $bitsLeft) & 31];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $output;
    }
}
