<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Crypto;
use Tests\TestCase;

class CryptoTest extends TestCase
{
    public function test_encrypt_decrypt_round_trip(): void
    {
        $plain = 'rcon-пароль-123';

        $encrypted = Crypto::encrypt($plain);

        $this->assertNotSame($plain, $encrypted);
        $this->assertSame($plain, Crypto::decrypt($encrypted));
    }

    public function test_ciphertext_differs_between_calls(): void
    {
        // Случайный IV: одинаковые открытые тексты должны давать разные шифротексты
        $this->assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'));
    }

    public function test_null_and_empty_stay_null(): void
    {
        $this->assertNull(Crypto::encrypt(null));
        $this->assertNull(Crypto::encrypt(''));
        $this->assertNull(Crypto::decrypt(null));
        $this->assertNull(Crypto::decrypt(''));
    }

    public function test_decrypt_rejects_garbage(): void
    {
        $this->assertNull(Crypto::decrypt('не-base64-мусор!!'));
        $this->assertNull(Crypto::decrypt(base64_encode('коротко')));
        $this->assertNull(Crypto::decrypt(base64_encode(random_bytes(64))));
    }

    public function test_mask_hides_middle_of_long_value(): void
    {
        $masked = Crypto::mask('abcdefghijklmnop');

        $this->assertStringStartsWith('abcd', $masked);
        $this->assertStringEndsWith('mnop', $masked);
        $this->assertStringContainsString('…', $masked);
        $this->assertStringNotContainsString('efghijkl', $masked);
    }

    public function test_mask_hides_short_value_entirely(): void
    {
        $this->assertSame('••••', Crypto::mask('abcd'));
        $this->assertSame('', Crypto::mask(null));
        $this->assertSame('', Crypto::mask(''));
    }

    public function test_random_password_has_requested_length_and_ambiguous_chars(): void
    {
        $password = Crypto::randomPassword(24);

        $this->assertSame(24, mb_strlen($password));
        // В алфавите намеренно нет похожих символов 0/O, 1/l, I
        $this->assertDoesNotMatchRegularExpression('/[01lIO]/', $password);
    }

    public function test_random_token_is_hex_of_expected_size(): void
    {
        $token = Crypto::randomToken(32);

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }
}
