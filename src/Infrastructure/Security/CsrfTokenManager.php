<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use InvalidArgumentException;

final readonly class CsrfTokenManager
{
    public const int DEFAULT_TTL = 7200; // 2 hours

    public function __construct(
        private string $secretKey,
    ) {
    }

    public function generateToken(string $sessionId, int $ttl = self::DEFAULT_TTL): string
    {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('Session ID cannot be empty.');
        }

        $expires = time() + $ttl;
        $signature = hash_hmac('sha256', $sessionId . ':' . (string) $expires, $this->secretKey);

        return base64_encode(sprintf('%s:%d:%s', $sessionId, $expires, $signature));
    }

    public function validateToken(string $sessionId, string $token): bool
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }

        [$tokenSessionId, $expiresStr, $providedSig] = $parts;

        if ($tokenSessionId !== $sessionId) {
            return false;
        }

        $expires = (int) $expiresStr;
        if (time() > $expires) {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $sessionId . ':' . $expiresStr, $this->secretKey);

        return hash_equals($expectedSig, $providedSig);
    }
}
