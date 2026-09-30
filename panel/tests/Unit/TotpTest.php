<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * TOTP проверяется по контрольным векторам RFC 6238 (HMAC-SHA1).
 * Секрет «12345678901234567890» в base32 — GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6238#appendix-B
 */
class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private Totp $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Totp;
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rfcVectors')]
    public function test_code_matches_rfc6238_vectors(int $timestamp, string $expected): void
    {
        $counter = intdiv($timestamp, 30);

        $this->assertSame($expected, $this->totp->codeAt(self::SECRET, $counter));
    }

    public function test_generated_secret_is_valid_base32_of_expected_length(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_verify_accepts_current_code(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->currentCode($secret);

        $this->assertTrue($this->totp->verify($secret, $code));
    }

    public function test_verify_tolerates_surrounding_spaces(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->currentCode($secret);

        $this->assertTrue($this->totp->verify($secret, ' '.$code.' '));
    }

    public function test_verify_rejects_code_from_far_window(): void
    {
        $secret = $this->totp->generateSecret();
        // Код, вычисленный за 10 окон назад — за пределами допуска ±1
        $stale = $this->totp->codeAt($secret, intdiv(time(), 30) - 10);

        $this->assertFalse($this->totp->verify($secret, $stale));
    }

    public function test_verify_rejects_wrong_length(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertFalse($this->totp->verify($secret, '12345'));
        $this->assertFalse($this->totp->verify($secret, '1234567'));
        $this->assertFalse($this->totp->verify($secret, ''));
    }

    public function test_verify_rejects_wrong_secret(): void
    {
        $code = $this->totp->currentCode($this->totp->generateSecret());

        $this->assertFalse($this->totp->verify($this->totp->generateSecret(), $code));
    }

    public function test_base32_round_trip(): void
    {
        $raw = random_bytes(20);

        $encoded = $this->totp->base32Encode($raw);

        $this->assertSame($raw, $this->totp->base32Decode($encoded));
    }

    public function test_base32_decode_ignores_padding_and_case(): void
    {
        $this->assertSame(
            $this->totp->base32Decode(self::SECRET),
            $this->totp->base32Decode(strtolower(self::SECRET).'======'),
        );
    }

    public function test_otpauth_url_is_parsable(): void
    {
        $url = $this->totp->otpauthUrl(self::SECRET, 'user@example.com', 'GameDock');

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('secret='.self::SECRET, $url);
        $this->assertStringContainsString('issuer=GameDock', $url);
        $this->assertStringContainsString('period=30', $url);
    }

    public function test_recovery_codes_are_unique_lowercase_hex(): void
    {
        $codes = $this->totp->generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $code);
        }
    }
}
