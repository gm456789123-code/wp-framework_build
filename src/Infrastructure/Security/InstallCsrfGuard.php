<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Double-submit CSRF protection for the 1-click web installer. A random
 * token is set as both an httponly cookie and a hidden form field whenever
 * the install page renders; a cross-site attacker can forge the hidden
 * field value but cannot read or set a cookie on this origin from their
 * own page, so the two only match when the browser submitting the form is
 * the one that loaded it. No server-side session is needed (none exists
 * pre-install), and nothing the admin has to create or edit by hand.
 */
final class InstallCsrfGuard
{
    private const string COOKIE_NAME = 'install_csrf';
    private const string FIELD_NAME = 'install_csrf';
    private const int TTL_SECONDS = 1800;

    public function issueToken(bool $isHttps): string
    {
        $token = bin2hex(random_bytes(32));
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::TTL_SECONDS,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return $token;
    }

    /**
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $postedFields
     */
    public function isValid(array $cookies, array $postedFields): bool
    {
        $cookieToken = self::stringValue($cookies, self::COOKIE_NAME);
        $postedToken = self::stringValue($postedFields, self::FIELD_NAME);

        return $cookieToken !== '' && $postedToken !== '' && hash_equals($cookieToken, $postedToken);
    }

    /**
     * @param array<string, mixed> $values
     * @psalm-suppress MixedAssignment
     */
    private static function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
