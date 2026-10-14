<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Infrastructure\Security\CsrfTokenManager;

final readonly class CsrfController
{
    public function __construct(
        private CsrfTokenManager $csrfManager,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function getToken(string $sessionId): array
    {
        $token = $this->csrfManager->generateToken($sessionId);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, private',
            ],
            'body' => (string) json_encode(['csrf_token' => $token]),
        ];
    }
}
