<?php

declare(strict_types=1);

// Sovereign Engine front controller for public pages. Deliberately does
// NOT load WordPress at all (no wp-load.php/wp-settings.php): nginx routes
// /wp-admin, /wp-login.php, /wp-cron.php etc. to WordPress's own files
// unchanged, and routes everything else here, so a normal page view never
// pays for a full WP boot + WP_Query just to be overridden afterwards.

// Self-locating: this file always lives at <project-root>/public/front.php,
// so the project root is always one level up, regardless of where the
// project was cloned/deployed -- no path needs editing here.
$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';

use App\Application\Context\RequestLocaleContext;
use App\Application\UseCase\Page\GetLatestPublishedPageId;
use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\SearchPages;
use App\Infrastructure\Cache\RedisTaggedCache;
use App\Infrastructure\Env;
use App\Infrastructure\Installer\SmartBootstrapper;
use App\Infrastructure\Search\MySqlFullTextSearch;
use App\Infrastructure\Security\CsrfTokenManager;
use App\Infrastructure\Security\InstallCsrfGuard;
use App\Infrastructure\Security\RateLimiter;
use App\Infrastructure\Storage\SignedUrlGenerator;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use App\Presentation\Http\CsrfController;
use App\Presentation\Http\PageController;
use App\Presentation\Http\Router;
use App\Presentation\Http\SearchController;
use App\Presentation\Http\SecureFileController;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;

// Load .env into $_ENV before anything reads a config value. Real process
// env vars (Docker, hosting panel) always win -- Env::loadFile() never
// overwrites a key that's already set.
Env::loadFile($projectRoot . '/.env');

// Safe asset serving for logo/css/favicon so it never 404s on any host or domain
if (isset($_GET['asset']) && ($_GET['asset'] === 'logo.webp' || $_GET['asset'] === 'logo.png' || $_GET['asset'] === 'sovereign.css')) {
    $file = $_GET['asset'];
    $mime = match ($file) {
        'logo.webp' => 'image/webp',
        'logo.png' => 'image/png',
        'sovereign.css' => 'text/css; charset=UTF-8',
    };
    $candidates = [
        $projectRoot . '/public/assets/' . $file,
        $projectRoot . '/assets/' . $file,
        $projectRoot . '/themes/sovereign/assets/' . $file,
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            header("Content-Type: {$mime}");
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; frame-ancestors 'self';");
            readfile($candidate);
            exit;
        }
    }
}

// Auto-detect current protocol and host with strict hostname validation
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$rawHost = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost:8080';
$host = preg_match('/^[a-zA-Z0-9.-]+(:[0-9]{1,5})?$/', $rawHost) === 1 ? $rawHost : 'localhost:8080';
$detectedSiteUrl = "{$scheme}://{$host}";
$appUrl = Env::string('APP_URL', '');
$siteUrl = ($appUrl !== '' && !str_contains($appUrl, 'localhost'))
    ? rtrim($appUrl, '/')
    : rtrim($appUrl !== '' ? $appUrl : $detectedSiteUrl, '/');

$logoUrl = is_file($projectRoot . '/public/assets/logo.webp') ? '?asset=logo.webp' : '?asset=logo.png';
$cssUrl = '?asset=sovereign.css';

// Baseline security headers for all HTTP responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$latte = new Engine();
$latte->setTempDirectory(sys_get_temp_dir());

$isLocal = Env::string('APP_ENV', 'production') === 'local';
$installCsrfGuard = new InstallCsrfGuard();

// -------------------------------------------------------------
// Resolve install state the same way WordPress itself does: by whether
// wp-config.php exists on disk. This never depends on .env/process env
// vars, so a visitor on fresh shared hosting -- with no file edited by
// hand -- always reaches either the installer or the site.
// -------------------------------------------------------------
$bootstrapper = new SmartBootstrapper();
$envInfo = $bootstrapper->detectEnvironment();
$targetDir = $envInfo['targetDir'];
$configExists = $envInfo['configExists'];

if ($configExists) {
    $configFile = is_file($targetDir . '/wp-config.php')
        ? $targetDir . '/wp-config.php'
        : dirname($targetDir) . '/wp-config.php';
    require_once $configFile;
}

// -------------------------------------------------------------
// Web Installer Action: 1-Click Bootstrap from Browser
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'install'
) {
    // Gate: rate limit by IP before any other work. This is the only real
    // defense against a "bump script" racing a human to be first through
    // the installer (a secret SETUP_TOKEN would also stop it, but that's
    // exactly the friction this project chose not to impose on a first-
    // time admin) -- it can't stop a single fast, well-timed attempt, but
    // it stops the repeated poll-and-retry pattern such a script relies on.
    $clientIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $installRateLimiter = new RateLimiter($projectRoot . '/var/ratelimit');
    if ($installRateLimiter->tooManyAttempts("install:{$clientIp}", maxAttempts: 5, windowSeconds: 600)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Retry-After: 600');
        echo 'Too many install attempts from this address. Please wait before trying again.';
        exit;
    }

    // Gate: CSRF double-submit check. Must hold before any other work --
    // a cross-site auto-submitting form can guess/forge every other field
    // here (db_host, admin_password, ...), so this is the one check that
    // actually proves the request came from a page this origin served.
    if (!$installCsrfGuard->isValid($_COOKIE, $_POST)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid or expired install request. Please reload the page and try again.';
        exit;
    }

    // Gate: serialize concurrent install attempts with a non-blocking file
    // lock. Without this, a burst of simultaneous requests (bump script,
    // or someone double-clicking Install) can all pass the "not yet
    // installed" check at once and race to write wp-config.php / call
    // wp_install() concurrently -- the same class of bug MerkleAuditLogger
    // guards against with MySQL's GET_LOCK, just file-based since there's
    // no guaranteed DB connection this early. Non-blocking (LOCK_EX |
    // LOCK_NB) on purpose: a losing request gets an immediate, clear
    // answer instead of hanging behind a WordPress core download that can
    // take up to 180 seconds, which would otherwise tie up PHP-FPM workers
    // and open its own resource-exhaustion angle. The lock is released
    // implicitly when the request ends (every exit below ends the PHP
    // process), which is the normal, safe way to release a flock() in a
    // one-request-per-process model.
    $installLockPath = $projectRoot . '/var/install.lock';
    if (!is_dir(dirname($installLockPath))) {
        @mkdir(dirname($installLockPath), 0755, true);
    }
    $installLockHandle = @fopen($installLockPath, 'c');
    if ($installLockHandle === false || !flock($installLockHandle, LOCK_EX | LOCK_NB)) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'An installation is already in progress. Please wait a moment and reload.';
        exit;
    }

    // Gate: already installed → refuse. Mirrors wp-admin/install.php's own
    // is_blog_installed() check: once wp_posts exists, nobody can run the
    // installer again, authenticated or not.
    $alreadyInstalled = false;
    if ($configExists) {
        try {
            $checkPdo = WpdbConnection::get();
            $stmt = $checkPdo->query("SHOW TABLES LIKE 'wp_posts'");
            $alreadyInstalled = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable) {
            // DB unreachable — not (yet) installed.
        }
    }
    if ($alreadyInstalled) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Installation has already been completed.';
        exit;
    }

    $logs = [];
    $logger = function (string $msg) use (&$logs): void {
        $logs[] = $msg;
    };

    // Standard WordPress-style setup: DB credentials are typed directly
    // into the browser form (like wp-admin/setup-config.php), never read
    // from a file the admin has to create or edit by hand.
    $dbHostInput = trim(is_string($_POST['db_host'] ?? null) ? $_POST['db_host'] : 'localhost');
    $dbHostInput = $dbHostInput !== '' ? $dbHostInput : 'localhost';
    $dbNameInput = trim(is_string($_POST['db_name'] ?? null) ? $_POST['db_name'] : '');
    $dbUserInput = trim(is_string($_POST['db_user'] ?? null) ? $_POST['db_user'] : '');
    $dbPassInput = is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '';

    try {
        if ($configExists) {
            // wp-config.php already has credentials from a previous step
            // (e.g. install was interrupted before tables were provisioned)
            // — reuse that connection instead of asking again.
            $pdo = WpdbConnection::get();
            $dbConfig = null;
        } else {
            if ($dbNameInput === '' || $dbUserInput === '') {
                throw new RuntimeException('Database name and username are required.');
            }

            $pdo = new PDO(
                "mysql:host={$dbHostInput};charset=utf8mb4",
                $dbUserInput,
                $dbPassInput,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $safeDbName = str_replace('`', '', $dbNameInput);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4");
            $pdo->exec("USE `{$safeDbName}`");

            $dbConfig = ['host' => $dbHostInput, 'db' => $dbNameInput, 'user' => $dbUserInput, 'pass' => $dbPassInput];
        }

        $bootstrapper = new SmartBootstrapper($pdo);
        $env = $bootstrapper->detectEnvironment($targetDir);

        if (!$env['coreFilesExist']) {
            $bootstrapper->downloadCore($targetDir, $logger);
        }

        if (!$env['configExists'] && $dbConfig !== null) {
            $bootstrapper->generateWpConfig($targetDir, $dbConfig, $logger);
        }

        $adminUser = 'admin';
        $adminPassword = $_POST['admin_password'] ?? 'Sovereign@' . date('Y');
        if (!is_string($adminPassword) || trim($adminPassword) === '') {
            $adminPassword = 'Sovereign@' . date('Y');
        }
        $adminEmail = 'admin@' . parse_url($siteUrl, PHP_URL_HOST);

        $bootstrapper->provisionDatabase($targetDir, $logger, $adminUser, $adminPassword, $adminEmail);
        $bootstrapper->activateSovereignTheme($logger);

        header('Content-Type: text/html; charset=UTF-8');
        echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
            'siteName' => 'Sovereign Engine',
            'logoUrl' => $logoUrl,
            'cssUrl' => $cssUrl,
            'isLocal' => $isLocal,
            'installSuccess' => true,
            'adminUser' => $adminUser,
            'adminPassword' => $adminPassword,
            'adminUrl' => $siteUrl . '/wp-admin/',
            'siteUrl' => $siteUrl,
            'logs' => $logs,
        ]);
        exit;
    } catch (Throwable $e) {
        $logs[] = '[ERROR] ' . $e->getMessage();
        header('Content-Type: text/html; charset=UTF-8');
        echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
            'siteName' => 'Sovereign Engine',
            'logoUrl' => $logoUrl,
            'cssUrl' => $cssUrl,
            'isLocal' => $isLocal,
            'installError' => $e->getMessage(),
            'logs' => $logs,
            'needsDbCredentials' => !$configExists,
            'dbHost' => $dbHostInput,
            'dbName' => $dbNameInput,
            'dbUser' => $dbUserInput,
        ]);
        exit;
    }
}

// -------------------------------------------------------------
// Normal Public Request Pipeline
// -------------------------------------------------------------

// Not installed yet → always show the installer. There's no DB to reach
// yet, so this never depends on guessing credentials from .env.
if (!$configExists) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
        'siteName' => 'Sovereign Engine',
        'logoUrl' => $logoUrl,
        'cssUrl' => $cssUrl,
        'isLocal' => $isLocal,
        'needsDbCredentials' => true,
        'installCsrfToken' => $installCsrfGuard->issueToken($scheme === 'https'),
    ]);
    exit;
}

// wp-config.php exists — its DB_* constants were require_once'd above, so
// WpdbConnection now reads the real credentials instead of .env defaults.
$pdo = null;
$dbError = false;
try {
    $pdo = WpdbConnection::get();
} catch (Throwable) {
    $dbError = true;
}

$tablesExist = false;
if ($pdo !== null) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'wp_posts'");
        $tablesExist = $stmt !== false && $stmt->fetch() !== false;
    } catch (Throwable) {
        $dbError = true;
    }
}

// C6: config exists but DB unreachable → real outage (503 maintenance, no
// install button). Config exists but tables missing → install was
// interrupted; resume it (wp-config.php already has credentials, so the
// form won't ask for them again).
if ($pdo === null || !$tablesExist) {
    if ($dbError) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: 300');
        echo $latte->renderToString($projectRoot . '/templates/maintenance.latte', [
            'siteName' => 'Sovereign Engine',
            'logoUrl' => $logoUrl,
            'isLocal' => $isLocal,
        ]);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
        'siteName' => 'Sovereign Engine',
        'logoUrl' => $logoUrl,
        'cssUrl' => $cssUrl,
        'isLocal' => $isLocal,
        'needsDbCredentials' => false,
        'installCsrfToken' => $installCsrfGuard->issueToken($scheme === 'https'),
    ]);
    exit;
}

// Step 2: serve a published page.
try {
    $repository = new WpPageRepository($pdo);

    $cache = null;
    try {
        $redis = new Redis();
        $redis->connect(Env::string('REDIS_HOST', 'redis'), Env::int('REDIS_PORT', 6379), 1.0);
        $cache = new RedisTaggedCache($redis);
    } catch (Throwable) {
        $cache = null;
    }

    $controller = new PageController(
        getPage: new GetPage($repository),
        parser: new BlockAstParser(Env::string('APP_URL', '')),
        renderer: new BlockRenderer($latte, $projectRoot . '/templates/blocks'),
        cache: $cache,
        pdo: $pdo,
        isLocal: $isLocal,
        latte: $latte,
        layoutPath: $projectRoot . '/templates/layout.latte',
        siteUrl: $siteUrl,
        adminUrl: $siteUrl . '/wp-admin/',
        logoUrl: $logoUrl,
        cssUrl: $cssUrl,
    );

    // No hardcoded fallback here on purpose: this codebase is public, so
    // any literal default would just be a known key anyone could read on
    // GitHub. A freshly installed site always has a real random APP_KEY
    // (written into wp-config.php by generateWpConfig(), same as the WP
    // salts); a site installed before that existed has none yet, and in
    // that case these two features fail closed (503) rather than sign
    // anything with a predictable key.
    $appKey = Env::appKey();
    $csrfController = null;
    $secureFileController = null;
    if ($appKey !== '') {
        $csrfManager = new CsrfTokenManager($appKey);
        $csrfController = new CsrfController($csrfManager);

        $urlGenerator = new SignedUrlGenerator($appKey, $siteUrl);
        $vaultDir = $projectRoot . '/var/vault';
        $secureFileController = new SecureFileController($urlGenerator, $vaultDir);
    }

    $searchRepo = new MySqlFullTextSearch($pdo);
    $searchPages = new SearchPages($searchRepo);
    $searchController = new SearchController(
        searchPages: $searchPages,
        latte: $latte,
        templatePath: $projectRoot . '/templates/search.latte',
        siteName: 'Sovereign Engine',
        siteUrl: $siteUrl,
        logoUrl: $logoUrl,
        cssUrl: $cssUrl,
        isLocal: $isLocal,
    );

    $router = new Router(
        repository: $repository,
        pageController: $controller,
        secureFileController: $secureFileController,
        csrfController: $csrfController,
        searchController: $searchController,
        pdo: $pdo,
        siteUrl: $siteUrl,
        isLocal: $isLocal,
        rateLimiter: new RateLimiter($projectRoot . '/var/ratelimit'),
        localeContext: new RequestLocaleContext(),
    );

    $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';

    /** @var array<string, string> $serverVars */
    $serverVars = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($v)) {
            $serverVars[$k] = $v;
        }
    }

    $response = $router->dispatch($method, $uri, $_GET, $serverVars);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("{$name}: {$value}");
    }

    if (!empty($response['filePath']) && is_file($response['filePath'])) {
        readfile($response['filePath']);
        exit;
    }

    if (isset($response['body'])) {
        echo $response['body'];
    } elseif (!empty($response['error'])) {
        echo $response['error'];
    }
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 300');
    echo $latte->renderToString($projectRoot . '/templates/maintenance.latte', [
        'siteName' => 'Sovereign Engine',
        'logoUrl' => $logoUrl,
        'isLocal' => $isLocal,
    ]);
}
