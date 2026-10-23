<?php

declare(strict_types=1);

namespace App\Infrastructure\Installer;

use App\Infrastructure\Env;
use PDO;
use SensitiveParameter;
use Throwable;

final class SmartBootstrapper
{
    private const string WP_DOWNLOAD_URL = 'https://wordpress.org/latest.tar.gz';

    public function __construct(
        private ?PDO $pdo = null,
    ) {
    }

    /**
     * @return array{
     *     projectRoot: string,
     *     targetDir: string,
     *     siteUrl: string,
     *     coreFilesExist: bool,
     *     configExists: bool,
     *     dbConnected: bool,
     *     tablesExist: bool,
     *     themeActive: bool
     * }
     */
    public function detectEnvironment(?string $explicitTargetDir = null): array
    {
        $projectRoot = dirname(__DIR__, 3);

        $targetDir = $explicitTargetDir ?? $this->resolveTargetDirectory($projectRoot);
        $siteUrl = $this->resolveSiteUrl();

        $coreFilesExist = is_file($targetDir . '/wp-settings.php') && is_file($targetDir . '/wp-load.php');
        $configExists = is_file($targetDir . '/wp-config.php') || is_file(dirname($targetDir) . '/wp-config.php');

        $dbConnected = false;
        $tablesExist = false;
        $themeActive = false;

        try {
            if ($this->pdo !== null) {
                $dbConnected = true;
                $stmt = $this->pdo->query("SHOW TABLES LIKE 'wp_posts'");
                $tablesExist = $stmt !== false && $stmt->fetch() !== false;

                if ($tablesExist) {
                    $optStmt = $this->pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'stylesheet' LIMIT 1");
                    /** @var array{option_value: string}|false $row */
                    $row = $optStmt !== false ? $optStmt->fetch(PDO::FETCH_ASSOC) : false;
                    $themeActive = is_array($row) && $row['option_value'] === 'sovereign';
                }
            } elseif (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
                $dbConnected = true;
                $tablesExist = function_exists('is_blog_installed') && is_blog_installed();
                if ($tablesExist && function_exists('get_stylesheet')) {
                    $themeActive = get_stylesheet() === 'sovereign';
                }
            }
        } catch (Throwable) {
            $dbConnected = false;
        }

        return [
            'projectRoot' => $projectRoot,
            'targetDir' => $targetDir,
            'siteUrl' => $siteUrl,
            'coreFilesExist' => $coreFilesExist,
            'configExists' => $configExists,
            'dbConnected' => $dbConnected,
            'tablesExist' => $tablesExist,
            'themeActive' => $themeActive,
        ];
    }

    /**
     * Downloads WordPress Core from wordpress.org and unpacks it into the target directory.
     *
     * @param callable(string): void $logger
     */
    public function downloadCore(string $targetDir, callable $logger): bool
    {
        $logger('[FETCH] Initiating download of WordPress Core from wordpress.org...');

        $tempTar = sys_get_temp_dir() . '/wp_latest_' . bin2hex(random_bytes(4)) . '.tar.gz';
        $tempExtract = sys_get_temp_dir() . '/wp_extract_' . bin2hex(random_bytes(4));

        // 1. Download tarball
        $fp = fopen($tempTar, 'w+');
        if ($fp === false) {
            $logger('[ERROR] Could not open temporary file for writing.');
            return false;
        }

        $ch = curl_init(self::WP_DOWNLOAD_URL);
        if ($ch === false) {
            fclose($fp);
            $logger('[ERROR] Failed to initialize cURL.');
            return false;
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SovereignEngine-Bootstrapper/1.0');

        $success = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($success === false || $httpCode !== 200) {
            $logger("[ERROR] Download failed with HTTP status {$httpCode}.");
            @unlink($tempTar);
            return false;
        }

        $filesize = filesize($tempTar);
        $bytes = $filesize !== false ? $filesize : 0;
        $sizeMb = round($bytes / 1024 / 1024, 2);
        $logger("[DOWNLOAD] Downloaded {$sizeMb} MB successfully.");

        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        // 2. Extract tarball
        $logger('[EXTRACT] Unpacking archive...');
        if (!is_dir($tempExtract)) {
            mkdir($tempExtract, 0755, true);
        }

        $unpacked = false;
        if (function_exists('exec')) {
            $cmd = 'tar -xzf ' . escapeshellarg($tempTar) . ' -C ' . escapeshellarg($tempExtract) . ' 2>&1';
            /** @psalm-suppress ForbiddenCode */
            @exec($cmd, $output, $retCode);
            if (is_dir($tempExtract . '/wordpress')) {
                $unpacked = true;
            }
        }

        if (!$unpacked && class_exists(\PharData::class)) {
            try {
                $phar = new \PharData($tempTar);
                $phar->extractTo($tempExtract, null, true);
                if (is_dir($tempExtract . '/wordpress')) {
                    $unpacked = true;
                }
            } catch (Throwable $e) {
                $logger('[WARN] PharData extract warning: ' . $e->getMessage());
            }
        }

        @unlink($tempTar);

        $sourceDir = is_dir($tempExtract . '/wordpress') ? $tempExtract . '/wordpress' : $tempExtract;
        if (!is_dir($sourceDir) || !is_file($sourceDir . '/wp-settings.php')) {
            $logger('[ERROR] Unpacking failed: WordPress core files not found in archive.');
            return false;
        }

        // 3. Copy files into target directory (without overwriting existing custom themes)
        $logger("[BUILD] Deploying WordPress core files to {$targetDir}...");
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $this->copyDirectory($sourceDir, $targetDir);

        // Cleanup
        $this->removeDirectory($tempExtract);

        $logger('[SUCCESS] WordPress Core files successfully deployed.');
        return true;
    }

    /**
     * Generates a secure wp-config.php with dynamic salts.
     *
     * @param array{host: string, db: string, user: string, pass: string} $db
     * @param callable(string): void $logger
     */
    public function generateWpConfig(string $targetDir, array $db, callable $logger): bool
    {
        $logger('[CONFIG] Generating wp-config.php with cryptographic salts...');

        $keys = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ];

        $salts = '';
        foreach ($keys as $key) {
            $secret = bin2hex(random_bytes(32));
            $salts .= "define('{$key}', '{$secret}');\n";
        }

        // APP_KEY signs CSRF tokens, secure-file download URLs, and the
        // audit log's HMAC chain -- generated fresh per install, same as
        // the WordPress salts above, so no deployment ever relies on a
        // hardcoded default that's sitting in public source code.
        $appKey = bin2hex(random_bytes(32));

        // var_export() yields a correctly escaped PHP string literal, so a
        // quote or backslash in a credential can't terminate the string
        // and inject code into wp-config.php.
        $dbName = var_export($db['db'], true);
        $dbUser = var_export($db['user'], true);
        $dbPass = var_export($db['pass'], true);
        $dbHost = var_export($db['host'], true);

        $config = <<<PHP
<?php
/**
 * Sovereign Engine - Autonomous WordPress Configuration
 */

define('DB_NAME', {$dbName});
define('DB_USER', {$dbUser});
define('DB_PASSWORD', {$dbPass});
define('DB_HOST', {$dbHost});
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{$salts}
define('APP_KEY', '{$appKey}');

\$table_prefix = 'wp_';

define('WP_DEBUG', false);
define('DISALLOW_FILE_EDIT', true);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
PHP;

        $targetFile = rtrim($targetDir, '/') . '/wp-config.php';
        if (file_put_contents($targetFile, $config) === false) {
            $logger('[ERROR] Failed to write wp-config.php file.');
            return false;
        }

        $logger('[SUCCESS] wp-config.php created with unique cryptographic salts.');
        return true;
    }

    /**
     * Provisions the default WordPress database tables if not already installed.
     *
     * @param callable(string): void $logger
     * @psalm-suppress UnresolvableInclude
     */
    public function provisionDatabase(
        string $targetDir,
        callable $logger,
        string $adminUser = 'admin',
        #[SensitiveParameter] ?string $adminPassword = null,
        string $adminEmail = 'admin@example.com',
    ): bool {
        $wpLoad = rtrim($targetDir, '/') . '/wp-load.php';
        $upgrade = rtrim($targetDir, '/') . '/wp-admin/includes/upgrade.php';

        if (file_exists($wpLoad) && file_exists($upgrade)) {
            $logger('[SCHEMA] Initializing database tables and default content...');
            try {
                if (!defined('WP_INSTALLING')) {
                    define('WP_INSTALLING', true);
                }
                require_once $wpLoad;
                require_once $upgrade;
                if (function_exists('wp_install')) {
                    if (function_exists('is_blog_installed') && !is_blog_installed()) {
                        // Never default to a guessable password: whoever
                        // runs the installer first gets full control of the
                        // admin account, so a fixed "admin"/"admin" would
                        // just hand that to whoever's fastest, not whoever
                        // is authorized.
                        $password = $adminPassword ?? bin2hex(random_bytes(12));

                        $siteUrl = $this->resolveSiteUrl();
                        wp_install('Sovereign Framework', $adminUser, $adminEmail, true, '', $password);
                        if (function_exists('update_option')) {
                            update_option('siteurl', $siteUrl);
                            update_option('home', $siteUrl);
                        }
                        $logger('[SUCCESS] Default database tables and content provisioned.');
                        $logger("[CREDENTIALS] admin user: {$adminUser}");
                        $logger("[CREDENTIALS] admin password: {$password}");
                    }
                }
            } catch (Throwable $e) {
                $logger('[NOTICE] Database schema notice: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Activates the Sovereign Engine theme in wp_options.
     *
     * @param callable(string): void $logger
     */
    public function activateSovereignTheme(callable $logger): bool
    {
        if (function_exists('switch_theme')) {
            $logger('[THEME] Activating Sovereign Engine theme via WordPress Core API...');
            switch_theme('sovereign');
            $logger('[SUCCESS] Sovereign Engine theme is now active.');
            return true;
        }

        if ($this->pdo === null) {
            $logger('[WARN] No database connection available to activate theme.');
            return false;
        }

        $logger('[THEME] Activating Sovereign Engine theme in database...');

        try {
            $options = ['template' => 'sovereign', 'stylesheet' => 'sovereign', 'current_theme' => 'Sovereign Engine'];
            foreach ($options as $name => $value) {
                $check = $this->pdo->prepare("SELECT 1 FROM wp_options WHERE option_name = :name LIMIT 1");
                $check->execute(['name' => $name]);

                if ($check->fetch() !== false) {
                    $stmt = $this->pdo->prepare("UPDATE wp_options SET option_value = :val WHERE option_name = :name");
                    $stmt->execute(['name' => $name, 'val' => $value]);
                } else {
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO wp_options (option_name, option_value, autoload) VALUES (:name, :val, 'yes')"
                    );
                    $stmt->execute(['name' => $name, 'val' => $value]);
                }
            }
        } catch (Throwable $e) {
            $logger('[NOTICE] Database options notice: ' . $e->getMessage());
        }

        $logger('[SUCCESS] Sovereign Engine theme is now active.');
        return true;
    }

    private function resolveTargetDirectory(string $projectRoot): string
    {
        // 1. Check if running inside Docker with standard web root
        if (is_dir('/var/www/html')) {
            return '/var/www/html';
        }

        // 2. Check DOCUMENT_ROOT
        /** @var string $docRoot */
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot !== '' && is_dir($docRoot)) {
            return $docRoot;
        }

        // 3. Fallback to project root / public
        return $projectRoot;
    }

    private function resolveSiteUrl(): string
    {
        $appUrl = Env::string('APP_URL', '');
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        /** @var string $host */
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            /** @var string $serverPort */
            $serverPort = $_SERVER['SERVER_PORT'] ?? '';
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || $serverPort === '443';
            $scheme = $isHttps ? 'https' : 'http';

            return "{$scheme}://{$host}";
        }

        return 'http://localhost:8080';
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $dir = opendir($source);
        if ($dir === false) {
            return;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcFile = $source . '/' . $file;
            $dstFile = $destination . '/' . $file;

            if (is_dir($srcFile)) {
                if (!is_dir($dstFile)) {
                    mkdir($dstFile, 0755, true);
                }
                $this->copyDirectory($srcFile, $dstFile);
            } else {
                // Don't overwrite existing wp-config.php, index.php or custom files
                if (($file === 'wp-config.php' || $file === 'index.php') && file_exists($dstFile)) {
                    continue;
                }
                copy($srcFile, $dstFile);
            }
        }

        closedir($dir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        $files = $scanned !== false ? array_diff($scanned, ['.', '..']) : [];
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
