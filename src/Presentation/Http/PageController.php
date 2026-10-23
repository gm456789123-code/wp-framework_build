<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\ValueObject\PostId;
use App\Domain\ValueObject\SeoData;
use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Security\CspBridge;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\View\BlockRenderer;
use DateTimeInterface;
use Latte\Engine;
use PDO;

final readonly class PageController
{
    public function __construct(
        private GetPage $getPage,
        private BlockAstParser $parser,
        private BlockRenderer $renderer,
        private ?CacheInterface $cache = null,
        private ?PDO $pdo = null,
        private bool $isLocal = false,
        private ?Engine $latte = null,
        private ?string $layoutPath = null,
        private string $siteName = 'Sovereign Engine',
        private string $siteUrl = '',
        private string $adminUrl = '',
        private string $logoUrl = '',
        private string $cssUrl = '?asset=sovereign.css',
        private ?CspBridge $cspBridge = null,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function show(int $pageId, bool $isPreview = false): array
    {
        $startTime = microtime(true) * 1000;
        $cacheKey = "tenant_default:page:{$pageId}";
        $queryCount = 0;

        // 1. Warm cache check (0 DB queries if hit, bypassed if preview)
        if (!$isPreview && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                if ($this->isLocal) {
                    $cached .= (new DevToolbar())->render(0, $startTime, true);
                }

                $etag = '"' . md5($cached) . '"';
                $rawIfNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
                $ifNoneMatch = is_string($rawIfNoneMatch) ? trim($rawIfNoneMatch) : null;
                if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                    return [
                        'status' => 304,
                        'headers' => [
                            'ETag' => $etag,
                            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
                            'X-Content-Type-Options' => 'nosniff',
                            'X-Frame-Options' => 'SAMEORIGIN',
                            'Referrer-Policy' => 'strict-origin-when-cross-origin',
                        ],
                        'body' => '',
                    ];
                }

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'text/html; charset=UTF-8',
                        'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
                        'ETag' => $etag,
                        'X-Content-Type-Options' => 'nosniff',
                        'X-Frame-Options' => 'SAMEORIGIN',
                        'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    ],
                    'body' => $cached,
                ];
            }
        }

        // 2. Cold request: <= 3 queries (GetPage + Synced Patterns)
        $output = $this->getPage->handle(new GetPageInput(new PostId($pageId), $isPreview));
        $queryCount++;

        if ($output->page === null) {
            return $this->renderNotFound();
        }

        // 3. Ingest Gutenberg AST & Synced Patterns
        $blocks = $this->parser->parse($output->page->content);
        if ($this->pdo !== null) {
            $blocks = $this->parser->resolveSyncedPatterns($blocks, $this->pdo);
            $queryCount++;
        }

        // 4. Render pure Latte templates
        $renderedContent = $this->renderer->renderAll($blocks);

        // Self-locating fallback: this file always lives at
        // src/Presentation/Http/PageController.php, so three levels up is
        // always the project root, regardless of where it was deployed.
        $layoutPath = $this->layoutPath ?? dirname(__DIR__, 3) . '/templates/layout.latte';

        $alternateUrls = [];
        if ($this->siteUrl !== '') {
            $base = rtrim($this->siteUrl, '/');
            if ($output->page->slug !== '') {
                $encodedSlug = rawurlencode($output->page->slug);
                $alternateUrls = [
                    'th' => $base . '/' . $encodedSlug,
                    'en' => $base . '/en/' . $encodedSlug,
                    'x-default' => $base . '/' . $encodedSlug,
                ];
            } else {
                $alternateUrls = [
                    'th' => $base . '/',
                    'en' => $base . '/en',
                    'x-default' => $base . '/',
                ];
            }
        }

        $seoData = $output->page->buildSeoData($this->siteUrl, $alternateUrls);

        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $output->page->title,
            'description' => $seoData->description,
            'url' => $seoData->canonicalUrl,
            'datePublished' => $output->page->createdAt->format(DateTimeInterface::ATOM),
            'dateModified' => ($output->page->modifiedAt ?? $output->page->createdAt)->format(DateTimeInterface::ATOM),
            'inLanguage' => $output->page->locale->value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $bridge = $this->cspBridge ?? new CspBridge();
        $nonce = $bridge->generateNonce();
        $cspHeader = $bridge->buildHeader($nonce);

        $html = $this->latte !== null && is_file($layoutPath)
            ? $this->latte->renderToString($layoutPath, [
                'locale' => $output->page->locale->value,
                'title' => $output->page->title,
                'content' => $renderedContent,
                'siteName' => $this->siteName,
                'siteUrl' => $this->siteUrl,
                'adminUrl' => $this->adminUrl,
                'logoUrl' => $this->logoUrl,
                'cssUrl' => $this->cssUrl,
                'isLocal' => $this->isLocal,
                'seo' => $seoData->toArray(),
                'jsonLd' => $jsonLd !== false ? $jsonLd : null,
                'cspNonce' => $nonce,
            ])
            : sprintf(
                '<!DOCTYPE html><html lang="%s"><head><title>%s</title></head><body class="p-8 max-w-4xl mx-auto">%s</body></html>',
                htmlspecialchars($output->page->locale->value),
                htmlspecialchars($output->page->title),
                $renderedContent
            );

        // 5. Cache response if not preview
        if (!$isPreview && $this->cache !== null) {
            $this->cache->set($cacheKey, $html, 3600, ["page:{$pageId}"]);
        }

        if ($this->isLocal) {
            $html .= (new DevToolbar())->render($queryCount, $startTime, false);
            $html .= $bridge->renderDiagnosticObserverScript($nonce);
        }

        $etag = '"' . md5($html) . '"';
        $lastModified = ($output->page->modifiedAt ?? $output->page->createdAt)->format('D, d M Y H:i:s \G\M\T');

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => $isPreview ? 'no-cache, private' : 'public, max-age=60, stale-while-revalidate=300',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => $cspHeader,
        ];

        $rawIfNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        $ifNoneMatch = is_string($rawIfNoneMatch) ? trim($rawIfNoneMatch) : null;
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            return [
                'status' => 304,
                'headers' => $headers,
                'body' => '',
            ];
        }

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => $html,
        ];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function renderNotFound(string $message = 'Page Not Found'): array
    {
        $layoutPath = $this->layoutPath ?? dirname(__DIR__, 3) . '/templates/layout.latte';
        $notFoundSeo = new SeoData(
            title: $message,
            description: 'The requested page could not be found.',
            noIndex: true,
        );

        $bridge = $this->cspBridge ?? new CspBridge();
        $nonce = $bridge->generateNonce();
        $cspHeader = $bridge->buildHeader($nonce);

        $content = '<div class="text-center py-12"><h2 class="text-xl font-bold text-zinc-100 mb-2">' . htmlspecialchars($message) . '</h2><p class="text-zinc-400">The requested resource could not be found or has been moved.</p><div class="mt-6"><a href="' . htmlspecialchars($this->siteUrl . '/') . '" class="inline-flex items-center px-4 py-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-sm font-medium transition">&larr; Return to Home</a></div></div>';

        $html = $this->latte !== null && is_file($layoutPath)
            ? $this->latte->renderToString($layoutPath, [
                'locale' => 'en',
                'title' => $message,
                'content' => $content,
                'siteName' => $this->siteName,
                'siteUrl' => $this->siteUrl,
                'adminUrl' => $this->adminUrl,
                'logoUrl' => $this->logoUrl,
                'isLocal' => $this->isLocal,
                'seo' => $notFoundSeo->toArray(),
                'cspNonce' => $nonce,
            ])
            : sprintf('<!DOCTYPE html><html><head><title>%s</title><meta name="robots" content="noindex"></head><body><h1>%s</h1></body></html>', htmlspecialchars($message), htmlspecialchars($message));

        return [
            'status' => 404,
            'headers' => [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
                'Content-Security-Policy' => $cspHeader,
            ],
            'body' => $html,
        ];
    }
}
