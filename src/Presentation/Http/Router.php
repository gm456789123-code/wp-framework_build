<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Context\RequestLocaleContext;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Infrastructure\Env;
use App\Infrastructure\Security\RateLimiter;
use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class Router
{
    private RequestLocaleContext $localeContext;

    public function __construct(
        private PageRepositoryInterface $repository,
        private PageController $pageController,
        private ?SecureFileController $secureFileController = null,
        private ?CsrfController $csrfController = null,
        private ?SearchController $searchController = null,
        private ?PDO $pdo = null,
        private string $siteUrl = '',
        private bool $isLocal = false,
        private ?RateLimiter $rateLimiter = null,
        ?RequestLocaleContext $localeContext = null,
    ) {
        $this->localeContext = $localeContext ?? new RequestLocaleContext(Locale::Thai);
    }

    public function getLocaleContext(): RequestLocaleContext
    {
        return $this->localeContext;
    }

    /**
     * Dispatches an HTTP request to the matching controller or route handler.
     *
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $server
     * @return array{status: int, headers: array<string, string>, body?: string, filePath?: ?string, error?: ?string}
     */
    public function dispatch(string $method, string $uri, array $queryParams = [], array $server = []): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = '/';
        }

        // Normalize path: leading slash, no trailing slash (except root)
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        // 1. Route: /robots.txt
        if ($method === 'GET' && $path === '/robots.txt') {
            return $this->handleRobotsTxt();
        }

        // 2. Route: /sitemap.xml
        if ($method === 'GET' && $path === '/sitemap.xml') {
            return $this->handleSitemapXml();
        }

        // 3. Route: /api/v1/auth/csrf-token
        if ($method === 'GET' && $path === '/api/v1/auth/csrf-token') {
            return $this->handleCsrfToken($queryParams, $server);
        }

        // 4. Route: /secure-file/{uuid}
        if ($method === 'GET' && preg_match('#^/secure-file/([0-9a-fA-F\-]{36})$#', $path, $matches) === 1) {
            return $this->handleSecureFile($matches[1], $queryParams);
        }

        // Locale resolution & subpath normalization:
        // Supports /en, /en/, /en/some-slug, /th, /th/, /th/some-slug
        $activeLocale = $this->localeContext->getLocale();
        $searchLocale = null;
        $targetPath = $path;

        if (preg_match('#^/(en|th)(/.*)?$#i', $path, $localeMatches) === 1) {
            $prefix = strtolower($localeMatches[1]);
            $matchedLocale = Locale::tryFrom($prefix);
            if ($matchedLocale !== null) {
                $activeLocale = $matchedLocale;
                $searchLocale = $matchedLocale;
                $this->localeContext->setLocale($activeLocale);
                $remaining = $localeMatches[2] ?? '';
                $targetPath = '/' . trim($remaining, '/');
                if ($targetPath === '//') {
                    $targetPath = '/';
                }
            }
        } elseif (isset($queryParams['lang']) && is_string($queryParams['lang'])) {
            $matchedLocale = Locale::tryFrom(strtolower($queryParams['lang']));
            if ($matchedLocale !== null) {
                $activeLocale = $matchedLocale;
                $searchLocale = $matchedLocale;
                $this->localeContext->setLocale($activeLocale);
            }
        }

        // 5. Route: /api/v1/search (JSON API endpoint)
        if ($method === 'GET' && $path === '/api/v1/search') {
            if ($this->isSearchRateLimited($server)) {
                return $this->tooManySearchRequests();
            }

            $query = '';
            if (isset($queryParams['q']) && is_string($queryParams['q'])) {
                $query = $queryParams['q'];
            } elseif (isset($queryParams['s']) && is_string($queryParams['s'])) {
                $query = $queryParams['s'];
            }
            if ($this->searchController !== null) {
                return $this->searchController->search($query, $searchLocale, isJson: true);
            }
            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'Cache-Control' => 'no-cache, private',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'SAMEORIGIN',
                ],
                'body' => (string) json_encode(['query' => $query, 'count' => 0, 'results' => []]),
            ];
        }

        // 6. Route: /search or /en/search or /th/search or /?s=... (HTML Search Page)
        if ($method === 'GET' && ($targetPath === '/search' || (isset($queryParams['s']) && is_string($queryParams['s']) && $queryParams['s'] !== ''))) {
            if ($this->isSearchRateLimited($server)) {
                return $this->tooManySearchRequests();
            }

            $query = '';
            if (isset($queryParams['q']) && is_string($queryParams['q'])) {
                $query = $queryParams['q'];
            } elseif (isset($queryParams['s']) && is_string($queryParams['s'])) {
                $query = $queryParams['s'];
            }
            if ($this->searchController !== null) {
                return $this->searchController->search($query, $searchLocale, isJson: false);
            }
        }

        // 7. Route: / (Homepage or ?page_id=X / ?p=X legacy param / ?preview=true)
        if ($method === 'GET' && $targetPath === '/') {
            $pageIdParam = null;
            if (isset($queryParams['page_id']) && is_string($queryParams['page_id'])) {
                $pageIdParam = $queryParams['page_id'];
            } elseif (isset($queryParams['p']) && is_string($queryParams['p'])) {
                $pageIdParam = $queryParams['p'];
            }
            if (is_string($pageIdParam) && ctype_digit($pageIdParam) && (int) $pageIdParam > 0) {
                $pageId = (int) $pageIdParam;
                $isPreviewRequested = isset($queryParams['preview']) && ($queryParams['preview'] === 'true' || $queryParams['preview'] === '1');

                if ($isPreviewRequested) {
                    if (!$this->isAuthorizedPreview($pageId, $queryParams, $server)) {
                        return [
                            'status' => 403,
                            'headers' => [
                                'Content-Type' => 'text/html; charset=UTF-8',
                                'X-Content-Type-Options' => 'nosniff',
                                'X-Frame-Options' => 'SAMEORIGIN',
                            ],
                            'body' => '<!DOCTYPE html><html><body><h1>403 Forbidden: Unauthorized Preview Request</h1></body></html>',
                        ];
                    }

                    return $this->pageController->show($pageId, isPreview: true);
                }

                return $this->pageController->show($pageId);
            }

            $frontPageId = $this->repository->findFrontPageId($activeLocale);
            if ($frontPageId === null) {
                return $this->pageController->renderNotFound('Nothing published yet');
            }

            return $this->pageController->show($frontPageId->value);
        }

        // 6. Route: /{slug}
        if ($method === 'GET' && $targetPath !== '/') {
            $rawSlug = trim($targetPath, '/');
            // Decode percent-encoded Unicode/Thai slug
            $slug = rawurldecode($rawSlug);

            $page = $this->repository->findBySlug($slug, $activeLocale);
            if ($page !== null) {
                $isPreviewRequested = isset($queryParams['preview']) && ($queryParams['preview'] === 'true' || $queryParams['preview'] === '1');
                if ($isPreviewRequested) {
                    if (!$this->isAuthorizedPreview($page->id->value, $queryParams, $server)) {
                        return [
                            'status' => 403,
                            'headers' => [
                                'Content-Type' => 'text/html; charset=UTF-8',
                                'X-Content-Type-Options' => 'nosniff',
                                'X-Frame-Options' => 'SAMEORIGIN',
                            ],
                            'body' => '<!DOCTYPE html><html><body><h1>403 Forbidden: Unauthorized Preview Request</h1></body></html>',
                        ];
                    }

                    return $this->pageController->show($page->id->value, isPreview: true);
                }

                return $this->pageController->show($page->id->value);
            }
        }

        // 7. Unknown route or method -> Real 404 with noindex layout
        return $this->pageController->renderNotFound('Page Not Found');
    }

    /**
     * MySQL FULLTEXT queries aren't free, and this route has no auth gate
     * by design (search is public) -- without a limit, a script can burn
     * DB resources with repeated queries same as the installer could
     * before it got one.
     *
     * @param array<string, string> $server
     */
    private function isSearchRateLimited(array $server): bool
    {
        if ($this->rateLimiter === null) {
            return false;
        }

        $clientIp = $server['REMOTE_ADDR'] ?? 'unknown';

        return $this->rateLimiter->tooManyAttempts("search:{$clientIp}", maxAttempts: 30, windowSeconds: 60);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function tooManySearchRequests(): array
    {
        return [
            'status' => 429,
            'headers' => [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => '60',
            ],
            'body' => 'Too many search requests. Please slow down and try again shortly.',
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $server
     */
    private function isAuthorizedPreview(int $pageId, array $queryParams, array $server): bool
    {
        if ($this->isLocal) {
            return true;
        }

        // Deliberately does NOT trust a wordpress_logged_in_* cookie by
        // itself: that cookie is entirely client-supplied, and verifying it
        // correctly requires WordPress's own session-token lookup (a DB read
        // against wp_usermeta plus wp_hash()/salt-based HMAC checking) which
        // this front controller intentionally never loads. Trusting the
        // cookie's shape alone (name prefix + an unsigned expiry timestamp)
        // would let anyone forge it and read unpublished content -- so the
        // only accepted proof of authorization is a signed, server-issued
        // token tied to this specific page id.
        $token = null;
        if (isset($queryParams['preview_token']) && is_string($queryParams['preview_token'])) {
            $token = $queryParams['preview_token'];
        } elseif (isset($queryParams['_wpnonce']) && is_string($queryParams['_wpnonce'])) {
            $token = $queryParams['_wpnonce'];
        }
        if ($token !== null && $token !== '') {
            $appKey = Env::appKey();
            if ($appKey !== '') {
                // Full-length HMAC comparison only: accepting a truncated
                // prefix would trade 256 bits of security margin for 40
                // (10 hex chars), which is in brute-forceable range for
                // anyone willing to send enough requests -- nothing in
                // this codebase ever needed or generated a shortened
                // token, so there's no reason to accept one.
                $expected = hash_hmac('sha256', "preview:{$pageId}", $appKey);
                if (hash_equals($expected, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleRobotsTxt(): array
    {
        $siteUrl = rtrim($this->siteUrl, '/');
        $sitemapLine = $siteUrl !== '' ? "\nSitemap: {$siteUrl}/sitemap.xml\n" : '';

        $body = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n{$sitemapLine}";

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
            ],
            'body' => $body,
        ];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleSitemapXml(): array
    {
        $siteUrl = rtrim($this->siteUrl, '/');
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Homepage URLs
        $xml[] = '  <url>';
        $xml[] = '    <loc>' . htmlspecialchars($siteUrl . '/') . '</loc>';
        $xml[] = '    <changefreq>daily</changefreq>';
        $xml[] = '    <priority>1.0</priority>';
        $xml[] = '  </url>';
        $xml[] = '  <url>';
        $xml[] = '    <loc>' . htmlspecialchars($siteUrl . '/en') . '</loc>';
        $xml[] = '    <changefreq>daily</changefreq>';
        $xml[] = '    <priority>1.0</priority>';
        $xml[] = '  </url>';

        if ($this->pdo !== null) {
            try {
                $statement = $this->pdo->query(
                    "SELECT ID, post_name, locale, post_date, post_modified
                     FROM wp_posts
                     WHERE post_status = 'publish'
                       AND post_type IN ('page', 'post')
                       AND deleted_at IS NULL
                     ORDER BY post_modified DESC
                     LIMIT 1000",
                );

                if ($statement !== false) {
                    /** @var list<array{ID: int, post_name: string, locale?: string, post_date: string, post_modified: string}> $rows */
                    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $localePrefix = ($row['locale'] ?? '') === 'en' ? '/en/' : '/';
                        $slug = $row['post_name'] !== '' ? rawurlencode($row['post_name']) : '?page_id=' . $row['ID'];
                        $loc = htmlspecialchars($siteUrl . $localePrefix . $slug);

                        $rawDate = $row['post_modified'] !== '' && $row['post_modified'] !== '0000-00-00 00:00:00'
                            ? $row['post_modified']
                            : $row['post_date'];

                        $lastmod = (new DateTimeImmutable($rawDate))->format('Y-m-d');

                        $xml[] = '  <url>';
                        $xml[] = "    <loc>{$loc}</loc>";
                        $xml[] = "    <lastmod>{$lastmod}</lastmod>";
                        $xml[] = '    <changefreq>weekly</changefreq>';
                        $xml[] = '    <priority>0.8</priority>';
                        $xml[] = '  </url>';
                    }
                }
            } catch (Throwable) {
                // Return valid minimal sitemap if DB query fails
            }
        }

        $xml[] = '</urlset>';
        $body = implode("\n", $xml);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
            ],
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleCsrfToken(array $queryParams, array $server): array
    {
        if ($this->csrfController === null) {
            return [
                'status' => 503,
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
                'body' => (string) json_encode(['error' => 'CSRF service unavailable.']),
            ];
        }

        $sessionId = '';
        if (isset($queryParams['session_id']) && is_string($queryParams['session_id']) && $queryParams['session_id'] !== '') {
            $sessionId = $queryParams['session_id'];
        } elseif (isset($server['HTTP_X_SESSION_ID']) && $server['HTTP_X_SESSION_ID'] !== '') {
            $sessionId = $server['HTTP_X_SESSION_ID'];
        } else {
            $sessionId = bin2hex(random_bytes(16));
        }

        return $this->csrfController->getToken($sessionId);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array{status: int, headers: array<string, string>, body?: string, filePath?: ?string, error?: ?string}
     */
    private function handleSecureFile(string $uuid, array $queryParams): array
    {
        if ($this->secureFileController === null) {
            return [
                'status' => 503,
                'headers' => ['Content-Type' => 'text/plain'],
                'error' => 'Secure file service unavailable.',
            ];
        }

        $signature = '';
        if (isset($queryParams['sig']) && is_string($queryParams['sig'])) {
            $signature = $queryParams['sig'];
        }
        $expires = is_numeric($queryParams['exp'] ?? null) ? (int) $queryParams['exp'] : 0;

        return $this->secureFileController->download($uuid, $signature, $expires);
    }
}
