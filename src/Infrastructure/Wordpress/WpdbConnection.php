<?php

declare(strict_types=1);

namespace App\Infrastructure\Wordpress;

use App\Infrastructure\Env;
use PDO;

final class WpdbConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $host = self::resolveString('DB_HOST', 'DB_HOST', Env::string('WORDPRESS_DB_HOST', 'mysql'));
            $port = Env::string('DB_PORT', '3306');
            $database = self::resolveString('DB_NAME', 'DB_DATABASE', Env::string('WORDPRESS_DB_NAME', 'wordpress'));
            $username = self::resolveString('DB_USER', 'DB_USERNAME', Env::string('WORDPRESS_DB_USER', 'wordpress'));
            $password = self::resolveString('DB_PASSWORD', 'DB_PASSWORD', Env::string('WORDPRESS_DB_PASSWORD', 'wordpress'));

            self::$instance = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return self::$instance;
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    private static function resolveString(string $constantName, string $envKey, string $default): string
    {
        if (defined($constantName)) {
            $constantValue = constant($constantName);
            if (is_string($constantValue) && $constantValue !== '') {
                return $constantValue;
            }
        }

        return Env::string($envKey, $default);
    }
}
