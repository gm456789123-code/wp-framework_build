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

use App\Application\UseCase\Page\GetLatestPublishedPageId;
use App\Application\UseCase\Page\GetPage;
use App\Infrastructure\Cache\RedisTaggedCache;
use App\Infrastructure\Env;
use App\Infrastructure\Installer\SmartBootstrapper;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use App\Presentation\Http\PageController;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;

// Safe asset serving for logo/favicon so it never 404s on any host or domain
if (isset($_GET['asset']) && $_GET['asset'] === 'logo.png') {
    $candidates = [
        $projectRoot . '/public/assets/logo.png',
        $projectRoot . '/assets/logo.png',
        $projectRoot . '/themes/sovereign/assets/logo.png',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($candidate);
            exit;
        }
    }
}

// Auto-detect current protocol and host
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$detectedSiteUrl = "{$scheme}://{$host}";
$siteUrl = rtrim(Env::string('APP_URL', $detectedSiteUrl), '/');
if (str_contains($siteUrl, 'localhost') && !str_contains($host, 'localhost')) {
    $siteUrl = $detectedSiteUrl;
}
$logoUrl = '?asset=logo.png';

$latte = new Engine();
$latte->setTempDirectory(sys_get_temp_dir());

// -------------------------------------------------------------
// Web Installer Action: 1-Click Bootstrap from Browser
// -------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'install') {
    $logs = [];
    $logger = function(string $msg) use (&$logs): void {
        $logs[] = $msg;
    };

    try {
        $pdo = null;
        try {
            $pdo = WpdbConnection::get();
        } catch (Throwable) {
            $pdo = null;
        }

        $bootstrapper = new SmartBootstrapper($pdo);
        $env = $bootstrapper->detectEnvironment();
        $targetDir = $env['targetDir'];

        // 1. Download Core if needed
        if (!$env['coreFilesExist']) {
            $bootstrapper->downloadCore($targetDir, $logger);
        }

        // 2. Generate wp-config.php if needed
        if (!$env['configExists']) {
            $bootstrapper->generateWpConfig($targetDir, $logger);
        }

        // 3. Provision database tables
        $adminUser = 'admin';
        $adminPassword = $_POST['admin_password'] ?? 'Sovereign@' . date('Y');
        if (!is_string($adminPassword) || trim($adminPassword) === '') {
            $adminPassword = 'Sovereign@' . date('Y');
        }
        $adminEmail = 'admin@' . parse_url($siteUrl, PHP_URL_HOST);

        $bootstrapper->provisionDatabase($targetDir, $logger, $adminUser, $adminPassword, $adminEmail);

        // 4. Activate theme
        $bootstrapper->activateSovereignTheme($logger);

        // Render Install Success Page
        header('Content-Type: text/html; charset=UTF-8');
        echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
            'siteName' => 'Sovereign Engine',
            'logoUrl' => $logoUrl,
            'phpVersion' => PHP_VERSION,
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
            'phpVersion' => PHP_VERSION,
            'installError' => $e->getMessage(),
            'logs' => $logs,
        ]);
        exit;
    }
}

// -------------------------------------------------------------
// Normal Public Request Pipeline
// -------------------------------------------------------------
try {
    $pdo = WpdbConnection::get();
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
        isLocal: Env::string('APP_ENV', 'local') === 'local',
        latte: $latte,
        layoutPath: $projectRoot . '/templates/layout.latte',
        siteUrl: $siteUrl,
        adminUrl: $siteUrl . '/wp-admin/',
        logoUrl: $logoUrl,
    );

    $pageIdParam = $_GET['page_id'] ?? $_GET['p'] ?? null;
    $pageId = is_string($pageIdParam) && ctype_digit($pageIdParam) ? (int) $pageIdParam : null;

    if ($pageId === null) {
        $latest = (new GetLatestPublishedPageId($repository))->handle();
        $pageId = $latest->id?->value;
    }

    if ($pageId === null) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo $latte->renderToString($projectRoot . '/templates/layout.latte', [
            'locale' => 'en',
            'title' => 'Nothing published yet',
            'content' => '<p>Head to <a href="' . htmlspecialchars($siteUrl . '/wp-admin/') . '">wp-admin</a> to publish your first post.</p>',
            'siteName' => 'Sovereign Engine',
            'siteUrl' => $siteUrl,
            'adminUrl' => $siteUrl . '/wp-admin/',
            'logoUrl' => $logoUrl,
            'phpVersion' => PHP_VERSION,
        ]);
        exit;
    }

    $response = $controller->show($pageId);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $response['body'];
} catch (Throwable) {
    // WordPress isn't installed yet (wp_posts doesn't exist) or the
    // database is unreachable -- render pre-install starter with 1-click install button.
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo $latte->renderToString($projectRoot . '/templates/starter.latte', [
        'siteName' => 'Sovereign Engine',
        'logoUrl' => $logoUrl,
        'phpVersion' => PHP_VERSION,
    ]);
}
