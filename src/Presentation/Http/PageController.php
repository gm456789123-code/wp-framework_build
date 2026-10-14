<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\View\BlockRenderer;
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

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'text/html; charset=UTF-8',
                        'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
                    ],
                    'body' => $cached,
                ];
            }
        }

        // 2. Cold request: <= 3 queries (GetPage + Synced Patterns)
        $output = $this->getPage->handle(new GetPageInput(new PostId($pageId)));
        $queryCount++;

        if ($output->page === null) {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
                'body' => '<!DOCTYPE html><html><body><h1>Page Not Found</h1></body></html>',
            ];
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

        $html = $this->latte !== null && is_file($layoutPath)
            ? $this->latte->renderToString($layoutPath, [
                'locale' => $output->page->locale->value,
                'title' => $output->page->title,
                'content' => $renderedContent,
                'siteName' => $this->siteName,
                'siteUrl' => $this->siteUrl,
                'adminUrl' => $this->adminUrl,
                'logoUrl' => $this->logoUrl,
                'phpVersion' => PHP_VERSION,
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
        }

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => $isPreview ? 'no-cache, private' : 'public, max-age=60, stale-while-revalidate=300',
            ],
            'body' => $html,
        ];
    }
}
