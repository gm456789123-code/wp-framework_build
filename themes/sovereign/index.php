<?php

declare(strict_types=1);

// Non-colliding escaping helpers
if (!function_exists('sov_esc_html')) {
    function sov_esc_html(string $text): string {
        return function_exists('esc_html') ? esc_html($text) : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sov_esc_url')) {
    function sov_esc_url(string $url): string {
        return function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

// Installation is CLI-only (bin/console wp:install): an HTTP-reachable
// installer with no auth check lets the first unauthenticated visitor,
// not the site owner, win control of the admin account. See
// src/Presentation/Cli/Command/WpInstallCommand.php.

// Self-locating: this only resolves to the actual project root if
// wp-content/themes/sovereign is a symlink (or bind mount) to this
// project's themes/sovereign directory, not a copy of it -- __DIR__
// follows the link back to where this file really lives.
$projectRoot = dirname(__DIR__, 2);

$startTime = microtime(true);
$isWpRunning = function_exists('is_blog_installed');
$isWpInstalled = $isWpRunning && is_blog_installed();

// 1. If WordPress is running and viewing a specific Page or Post, render via Sovereign Engine Latte renderer
if ($isWpRunning && (is_page() || is_single()) && file_exists($projectRoot . '/vendor/autoload.php')) {
    require_once $projectRoot . '/vendor/autoload.php';

    $postId = (int) get_the_ID();
    if ($postId > 0) {
        $pdo = \App\Infrastructure\Wordpress\WpdbConnection::get();
        $repo = new \App\Infrastructure\Wordpress\WpPageRepository($pdo);
        $getPage = new \App\Application\UseCase\Page\GetPage($repo);
        $parser = new \App\Infrastructure\Wordpress\Gutenberg\BlockAstParser();
        $latte = new \Latte\Engine();
        $latte->setTempDirectory(sys_get_temp_dir());
        $renderer = new \App\Presentation\View\BlockRenderer($latte, $projectRoot . '/templates/blocks');

        $controller = new \App\Presentation\Http\PageController($getPage, $parser, $renderer, null, $pdo, true);
        $response = $controller->show($postId);

        status_header($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $response['body'];
        exit;
    }
}

$siteUrl = function_exists('home_url') ? home_url() : 'http://localhost:8080';
$adminUrl = function_exists('admin_url') ? admin_url() : 'http://localhost:8080/wp-admin';
$siteName = function_exists('get_bloginfo') ? (get_bloginfo('name') ?: 'Sovereign Engine') : 'Sovereign Engine';
$themeUrl = function_exists('get_stylesheet_directory_uri') ? get_stylesheet_directory_uri() : '/wp-content/themes/sovereign';
$logoUrl = $themeUrl . '/assets/logo.png';
$ttfb = round((microtime(true) - $startTime) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#09090b] text-zinc-200 antialiased selection:bg-zinc-800 selection:text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sov_esc_html($siteName) ?> — Sovereign Framework</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        code, pre, .font-mono { font-family: 'JetBrains Mono', monospace; }
        .terminal-scroll::-webkit-scrollbar { width: 4px; }
        .terminal-scroll::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 2px; }
    </style>
</head>
<body class="min-h-full flex flex-col justify-between p-6 sm:p-10 relative bg-[#09090b] text-zinc-200 antialiased overflow-x-hidden">

    <!-- Ambient Developer Background (Next.js / Vercel style) -->
    <div class="fixed inset-0 pointer-events-none z-0">
        <!-- Soft top spotlight -->
        <div class="absolute -top-32 left-1/2 -translate-x-1/2 w-[720px] h-[450px] bg-[radial-gradient(ellipse_at_center,rgba(56,189,248,0.07),transparent_70%)] blur-3xl"></div>
        
        <!-- Precision Grid with Soft Radial Mask -->
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:32px_32px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_40%,#000_40%,transparent_100%)]"></div>
        
        <!-- Subtle Vignette -->
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_50%,rgba(9,9,11,0.5)_100%)]"></div>
    </div>

<?php if ($isWpInstalled): ?>
    <!-- ========================================== -->
    <!-- 1. INDEXED WORDPRESS THEME VIEW (POST-BUILD) -->
    <!-- ========================================== -->
    <header class="max-w-4xl w-full mx-auto flex items-center justify-between text-xs text-zinc-400 relative z-10 pb-6 border-b border-zinc-900">
        <div class="flex items-center space-x-3">
            <img src="<?= sov_esc_url($logoUrl) ?>" alt="Logo" class="w-8 h-8 object-contain">
            <span class="font-semibold text-white text-sm"><?= sov_esc_html($siteName) ?></span>
            <span class="px-2 py-0.5 rounded bg-emerald-950/60 border border-emerald-800/60 text-emerald-400 font-mono text-[10px]">Theme Indexed & Active</span>
        </div>
        <div class="flex items-center space-x-4">
            <a href="<?= sov_esc_url($adminUrl) ?>" class="px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white transition flex items-center space-x-1.5">
                <span>wp-admin</span>
                <span>&rarr;</span>
            </a>
        </div>
    </header>

    <main class="max-w-4xl w-full mx-auto flex-1 my-10 relative z-10 space-y-6">
        <div class="p-6 rounded-2xl bg-zinc-900/30 border border-zinc-800/80 backdrop-blur-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-white tracking-tight">Sovereign Theme Index</h1>
                <p class="text-xs text-zinc-400 mt-1">WordPress Core active as headless engine. Rendering via Latte 3.x AST templates with zero WP CSS overhead.</p>
            </div>
            <a href="<?= sov_esc_url($siteUrl) ?>/sample-page/" class="px-4 py-2 rounded-lg bg-white text-zinc-950 font-medium text-xs hover:bg-zinc-200 transition shrink-0">
                View Sample Page &rarr;
            </a>
        </div>

        <?php if (have_posts()): ?>
            <div class="space-y-4">
                <?php while (have_posts()): the_post(); ?>
                    <article class="p-6 rounded-2xl bg-zinc-900/20 border border-zinc-800/60 space-y-3">
                        <div class="flex items-center justify-between text-xs text-zinc-500 font-mono">
                            <span><?= sov_esc_html(get_the_date()) ?></span>
                            <span class="text-sky-400">Latte AST</span>
                        </div>
                        <h2 class="text-lg font-semibold text-white">
                            <a href="<?= sov_esc_url(get_permalink()) ?>" class="hover:text-sky-300 transition">
                                <?= sov_esc_html(get_the_title()) ?>
                            </a>
                        </h2>
                        <div class="text-sm text-zinc-400 leading-relaxed">
                            <?php the_excerpt(); ?>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="p-8 rounded-2xl bg-zinc-900/20 border border-zinc-800/60 text-center">
                <p class="text-sm text-zinc-400">No posts published yet. Head to wp-admin to publish your first post.</p>
            </div>
        <?php endif; ?>
    </main>

<?php else: ?>
    <!-- ========================================== -->
    <!-- 2. BARE FRAMEWORK STARTER (PRE-BUILD VIEW) -->
    <!-- ========================================== -->
    <header class="max-w-4xl w-full mx-auto flex items-center justify-between text-xs text-zinc-500 relative z-10">
        <div class="flex items-center space-x-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
            <span class="font-mono text-zinc-400">sovereign-framework v0.1.0</span>
        </div>
        <div class="flex items-center space-x-2 text-[11px] font-mono text-zinc-500">
            <span>BARE FRAMEWORK MODE</span>
        </div>
    </header>

    <main class="max-w-xl w-full mx-auto flex-1 flex flex-col items-center justify-center text-center my-12 relative z-10">
        
        <!-- Clean Logo with Subtle Ambient Halo -->
        <div class="mb-6 relative">
            <div class="absolute inset-0 bg-sky-500/10 blur-xl rounded-full scale-125 -z-10"></div>
            <img src="<?= sov_esc_url($logoUrl) ?>" alt="Sovereign" class="w-20 h-20 sm:w-24 sm:h-24 object-contain mx-auto drop-shadow-lg">
        </div>

        <!-- Title & Subtitle -->
        <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight text-white mb-3">
            Sovereign Engine
        </h1>
        
        <p class="text-sm text-zinc-400 leading-relaxed max-w-md mb-8">
            A minimal headless framework for WordPress. Clean Hexagonal DDD architecture, zero frontend CSS bloat, and Latte 3.x templates.
        </p>

        <!-- Installation is CLI-only; see src/Presentation/Cli/Command/WpInstallCommand.php -->
        <div class="w-full max-w-lg mt-4 text-left">
            <div class="rounded-lg bg-zinc-950 border border-zinc-800 overflow-hidden shadow-xl">
                <div class="px-3 py-2 bg-zinc-900/80 border-b border-zinc-800 text-xs font-mono text-zinc-400">
                    Run this to install WordPress
                </div>
                <div class="p-4 font-mono text-xs text-zinc-300 bg-black/50">
                    docker compose exec app php bin/console wp:install
                </div>
            </div>
        </div>

    </main>
<?php endif; ?>

    <!-- Bottom Minimal Footer -->
    <footer class="max-w-4xl w-full mx-auto flex flex-col sm:flex-row items-center justify-between text-xs text-zinc-500 font-mono gap-2 pt-6 border-t border-zinc-900 relative z-10">
        <div>
            <span>PHP <?= PHP_VERSION ?></span>
            <span class="mx-2">•</span>
            <span>MySQL 8.4</span>
            <span class="mx-2">•</span>
            <span>Latency <?= $ttfb ?>ms</span>
        </div>
        <div>
            <span>Hexagonal DDD Engine</span>
        </div>
    </footer>

</body>
</html>
