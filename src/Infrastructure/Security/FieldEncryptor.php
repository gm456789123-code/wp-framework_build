<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

final readonly class FieldEncryptor
{
    private const string PREFIX = 'enc:v1:';

    public function __construct(
        private string $secretKey,
    ) {
        if (strlen($this->secretKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(
                sprintf('Encryption key must be exactly %d bytes, got %d.', SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($this->secretKey))
            );
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->secretKey);

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if (!$this->isEncrypted($payload)) {
            throw new InvalidArgumentException('Payload does not have the expected enc:v1: format.');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InvalidArgumentException('Malformed encrypted payload.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->secretKey);
        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed. Ciphertext may be corrupted or key is incorrect.');
        }

        return $decrypted;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
