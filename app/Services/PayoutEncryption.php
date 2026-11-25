<?php
declare(strict_types=1);

final class PayoutEncryption
{
    public static function encrypt(array $details): string
    {
        $key = self::key();
        $nonce = random_bytes(12);
        $tag = '';
        $plaintext = json_encode($details, JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt payout details');
        }

        return $nonce . $tag . $ciphertext;
    }

    public static function decrypt(string $payload): array
    {
        if (strlen($payload) < 29) {
            throw new RuntimeException('Encrypted payout details are invalid');
        }
        $plaintext = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt payout details');
        }
        $details = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new RuntimeException('Decrypted payout details have an invalid format');
        }

        return $details;
    }

    private static function key(): string
    {
        $encoded = getenv('APP_ENCRYPTION_KEY');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key');
        }

        return $key;
    }
}
