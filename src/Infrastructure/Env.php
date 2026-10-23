<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Env
{
    public static function string(string $key, string $default): string
    {
        $value = self::raw($key);

        return $value ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::raw($key);

        if ($value !== null && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::raw($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolves the app-wide signing secret used for CSRF tokens, signed
     * file URLs, and the audit log's HMAC chain. Deliberately has NO
     * hardcoded fallback string: this codebase is public, so any literal
     * default here would just be a known key anyone could read on GitHub.
     * `SmartBootstrapper::generateWpConfig()` writes a real random
     * `APP_KEY` constant into wp-config.php during install (the same way
     * it writes the WordPress salts) -- `defined('APP_KEY')` is checked
     * first so an installed site always gets that generated secret, with
     * `.env`'s `APP_KEY` only for local/Docker dev convenience. Callers
     * must treat an empty return as "no secret available" and fail closed
     * (deny, not sign with a weaker/predictable key).
     *
     * @psalm-suppress MixedAssignment
     */
    public static function appKey(): string
    {
        if (defined('APP_KEY')) {
            $value = constant('APP_KEY');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return self::string('APP_KEY', '');
    }

    /**
     * Loads KEY=VALUE pairs from a dotenv file into $_ENV. Real process
     * environment always wins over the file, so Docker/hosting panel
     * variables are never silently overridden by a stale .env.
     */
    public static function loadFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$key, $value] = $parts;
            $key = trim($key);
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            if (self::raw($key) !== null) {
                continue;
            }

            $_ENV[$key] = self::unquote(trim($value));
        }
    }

    private static function raw(string $key): ?string
    {
        $value = $_ENV[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        // PHP-FPM's default variables_order ("GPCS") leaves $_ENV empty, so
        // real environment variables are only visible through getenv().
        $fromProcess = getenv($key);

        return is_string($fromProcess) ? $fromProcess : null;
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($value, 1, -1);
            }
        }

        // Strip trailing inline comment on unquoted values: FOO=bar # note
        $hash = strpos($value, ' #');

        return $hash !== false ? rtrim(substr($value, 0, $hash)) : $value;
    }
}
