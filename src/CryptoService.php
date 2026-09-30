<?php

declare(strict_types=1);

/**
 * Encrypts sensitive OAuth credentials before storing them in MySQL.
 *
 * AES-256-GCM is used through OpenSSL. The encryption key must be supplied
 * through APP_ENCRYPTION_KEY and must decode to exactly 32 bytes.
 */
final class CryptoService
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY must be a base64 encoded 32-byte key.'
            );
        }

        $this->key = $key;
    }

    public function encrypt(?string $plainText): ?string
    {
        if ($plainText === null || $plainText === '') {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt sensitive data.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        $payload = base64_decode($encrypted, true);

        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Invalid encrypted value.');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);

        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Unable to decrypt sensitive data.');
        }

        return $plainText;
    }
}
