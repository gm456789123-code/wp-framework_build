<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Domain\ValueObject\UuidV7;
use App\Infrastructure\Storage\SignedUrlGenerator;
use InvalidArgumentException;

final readonly class SecureFileController
{
    public function __construct(
        private SignedUrlGenerator $urlGenerator,
        private string $vaultDirectory,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, filePath: ?string, error: ?string}
     */
    public function download(string $uuid, string $signature, int $expires): array
    {
        // Validate the uuid is actually a UUID *before* it ever reaches a
        // filesystem path, rather than relying solely on the HMAC signature
        // to rule out path traversal (../../etc/passwd etc.) -- the
        // signature protects against a forged uuid, not against a bug
        // elsewhere that signs something that isn't a real uuid to begin
        // with, or a leaked signing key.
        try {
            $validatedUuid = new UuidV7($uuid);
        } catch (InvalidArgumentException) {
            return [
                'status' => 400,
                'headers' => ['Content-Type' => 'text/plain'],
                'filePath' => null,
                'error' => 'Bad Request: malformed file identifier.',
            ];
        }

        if (!$this->urlGenerator->verifyUrl($uuid, $signature, $expires)) {
            return [
                'status' => 403,
                'headers' => ['Content-Type' => 'text/plain'],
                'filePath' => null,
                'error' => 'Forbidden: Invalid or expired signed download URL.',
            ];
        }

        $filePath = rtrim($this->vaultDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $validatedUuid->toString() . '.vault';
        if (!is_file($filePath)) {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'text/plain'],
                'filePath' => null,
                'error' => 'File not found in sensitive vault.',
            ];
        }

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $uuid),
                'Cache-Control' => 'no-store, private',
            ],
            'filePath' => $filePath,
            'error' => null,
        ];
    }
}
