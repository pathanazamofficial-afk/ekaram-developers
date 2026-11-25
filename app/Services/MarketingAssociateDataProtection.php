<?php
declare(strict_types=1);

final class MarketingAssociateDataProtection
{
    private const FIELDS = ['aadhaar', 'pan', 'account_number', 'ifsc_code', 'branch_location'];

    public static function encrypt(string $field, string $plaintext): string
    {
        self::validateField($field);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::associatedData($field)
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt marketing associate details');
        }

        return $nonce . $tag . $ciphertext;
    }

    public static function decrypt(string $field, string $payload): string
    {
        self::validateField($field);
        if (strlen($payload) < 29) {
            throw new RuntimeException('Encrypted marketing associate details are invalid');
        }
        $plaintext = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
            self::associatedData($field)
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt marketing associate details');
        }

        return $plaintext;
    }

    public static function fingerprint(string $field, string $normalizedValue): string
    {
        self::validateField($field);

        return hash_hmac(
            'sha256',
            self::associatedData($field) . "\0" . $normalizedValue,
            self::fingerprintKey(),
            true
        );
    }

    private static function encryptionKey(): string
    {
        return hash_hkdf('sha256', self::masterKey(), 32, 'ekaram-marketing-associate-encryption');
    }

    private static function fingerprintKey(): string
    {
        return hash_hkdf('sha256', self::masterKey(), 32, 'ekaram-marketing-associate-fingerprints');
    }

    private static function masterKey(): string
    {
        $encoded = getenv('APP_ENCRYPTION_KEY');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key');
        }

        return $key;
    }

    private static function associatedData(string $field): string
    {
        return 'ekaram:marketing-associate:' . $field;
    }

    private static function validateField(string $field): void
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException('Unsupported marketing associate data field');
        }
    }
}
