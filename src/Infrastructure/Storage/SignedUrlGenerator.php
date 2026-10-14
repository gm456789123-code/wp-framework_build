<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\ValueObject\UuidV7;
use InvalidArgumentException;

final readonly class SignedUrlGenerator
{
    public const int MAX_TTL_SECONDS = 600; // 10 minutes max TTL per spec

    public function __construct(
        private string $secretKey,
        private string $baseUrl = '',
    ) {
    }

    public function generateUrl(string $uuid, int $ttlSeconds = self::MAX_TTL_SECONDS): string
    {
        // Constructing UuidV7 validates the format and throws otherwise,
        // so a caller can never sign a URL for something that isn't really
        // a uuid (which SecureFileController::download() also re-validates
        // on the way in, as defense-in-depth against a leaked signing key).
        $uuid = (new UuidV7($uuid))->toString();

        if ($ttlSeconds <= 0 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException(
                sprintf('TTL must be between 1 and %d seconds.', self::MAX_TTL_SECONDS)
            );
        }

        $expires = time() + $ttlSeconds;
        $signature = $this->sign($uuid, $expires);

        $path = sprintf('/secure-file/%s?sig=%s&exp=%d', $uuid, $signature, $expires);

        return $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') . $path : $path;
    }

    public function verifyUrl(string $uuid, string $signature, int $expires): bool
    {
        if (time() > $expires) {
            return false;
        }

        $expectedSig = $this->sign($uuid, $expires);

        return hash_equals($expectedSig, $signature);
    }

    private function sign(string $uuid, int $expires): string
    {
        return hash_hmac('sha256', $uuid . ':' . (string) $expires, $this->secretKey);
    }
}
