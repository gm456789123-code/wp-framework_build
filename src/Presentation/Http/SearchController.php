<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\UseCase\Page\SearchPages;
use App\Application\UseCase\Page\SearchPagesInput;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\SeoData;
use App\Infrastructure\Security\CspBridge;
use Latte\Engine;

final readonly class SearchController
{
    public function __construct(
        private SearchPages $searchPages,
        private ?Engine $latte = null,
        private ?string $templatePath = null,
        private string $siteName = 'Sovereign Engine',
        private string $siteUrl = '',
        private string $logoUrl = '?asset=logo.webp',
        private string $cssUrl = '?asset=sovereign.css',
        private bool $isLocal = false,
        private ?CspBridge $cspBridge = null,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function search(string $query, ?Locale $locale = null, bool $isJson = false): array
    {
        $cleanQuery = trim($query);
        $output = $this->searchPages->handle(new SearchPagesInput(
            query: $cleanQuery,
            locale: $locale,
            limit: 20,
        ));

        if ($isJson) {
            $data = [
                'query' => $cleanQuery,
                'count' => count($output->pages),
                'results' => array_map(static fn ($p): array => [
                    'id' => $p->id->value,
                    'title' => $p->title,
                    'slug' => $p->slug,
                    'locale' => $p->locale->value,
                    'excerpt' => $p->excerpt,
                    'date' => $p->createdAt->format('Y-m-d H:i:s'),
                ], $output->pages),
            ];

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'Cache-Control' => 'no-cache, private',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'SAMEORIGIN',
                ],
                'body' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        $bridge = $this->cspBridge ?? new CspBridge();
        $nonce = $bridge->generateNonce();
        $cspHeader = $bridge->buildHeader($nonce);

        $templatePath = $this->templatePath ?? dirname(__DIR__, 3) . '/templates/search.latte';

        $title = $cleanQuery !== '' ? "Search: {$cleanQuery}" : 'Search';
        $seo = new SeoData(
            title: $title,
            description: "Search results for {$cleanQuery}",
            canonicalUrl: $this->siteUrl !== '' ? rtrim($this->siteUrl, '/') . '/search?q=' . urlencode($cleanQuery) : null,
            noIndex: true, // Search result pages should always be noindexed
        );

        $html = $this->latte !== null && is_file($templatePath)
            ? $this->latte->renderToString($templatePath, [
                'title' => $title,
                'query' => $cleanQuery,
                'results' => $output->pages,
                'siteName' => $this->siteName,
                'siteUrl' => $this->siteUrl,
                'logoUrl' => $this->logoUrl,
                'cssUrl' => $this->cssUrl,
                'isLocal' => $this->isLocal,
                'seo' => $seo->toArray(),
                'cspNonce' => $nonce,
            ])
            : sprintf(
                '<!DOCTYPE html><html><head><title>%s</title><meta name="robots" content="noindex"></head><body class="p-8 max-w-4xl mx-auto"><h1>%s</h1><p>Found %d results</p></body></html>',
                htmlspecialchars($title),
                htmlspecialchars($title),
                count($output->pages)
            );

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Content-Security-Policy' => $cspHeader,
            ],
            'body' => $html,
        ];
    }
}
