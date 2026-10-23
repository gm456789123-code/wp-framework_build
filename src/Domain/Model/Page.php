<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Domain\ValueObject\SeoData;
use DateTimeImmutable;

final readonly class Page
{
    public function __construct(
        public PostId $id,
        public string $title,
        public string $content,
        public PageStatus $status,
        public Locale $locale,
        public DateTimeImmutable $createdAt,
        public string $slug = '',
        public string $excerpt = '',
        public ?DateTimeImmutable $modifiedAt = null,
        public ?SeoData $seo = null,
        /**
         * Per-page title/description/image set by a third-party SEO plugin
         * (Yoast, RankMath, ...) via its own wp-admin meta box, read
         * directly from wp_postmeta by the repository. Deliberately kept
         * separate from $seo rather than merged into it: canonical/hreflang
         * still come from this app's own routing (buildSeoData() below),
         * since a plugin's idea of the canonical URL is based on stock
         * WordPress permalinks that don't necessarily match this app's
         * slug-based routes.
         */
        public ?string $seoTitleOverride = null,
        public ?string $seoDescriptionOverride = null,
        public ?string $seoOgImageOverride = null,
    ) {
    }

    /**
     * Builds or returns the SEO metadata for this page.
     *
     * @param array<string, string> $alternateUrls
     */
    public function buildSeoData(string $siteUrl = '', array $alternateUrls = []): SeoData
    {
        if ($this->seo !== null) {
            return $this->seo;
        }

        $title = $this->seoTitleOverride !== null && $this->seoTitleOverride !== ''
            ? $this->seoTitleOverride
            : $this->title;

        $description = $this->seoDescriptionOverride !== null && $this->seoDescriptionOverride !== ''
            ? $this->seoDescriptionOverride
            : $this->excerpt;
        if ($description === '') {
            $cleanContent = preg_replace('/<!--.*?-->/s', '', $this->content) ?? '';
            $plainText = trim(strip_tags($cleanContent));
            if (mb_strlen($plainText, 'UTF-8') > 160) {
                $description = mb_substr($plainText, 0, 157, 'UTF-8') . '...';
            } else {
                $description = $plainText;
            }
        }

        $canonicalUrl = null;
        if ($siteUrl !== '') {
            $base = rtrim($siteUrl, '/');
            $path = $this->slug !== '' ? '/' . rawurlencode($this->slug) : '/?page_id=' . $this->id->value;
            $canonicalUrl = $alternateUrls[$this->locale->value] ?? ($base . $path);
        }

        $hreflangs = $alternateUrls;
        if ($canonicalUrl !== null && $hreflangs === []) {
            $hreflangs[$this->locale->value] = $canonicalUrl;
        }

        return new SeoData(
            title: $title,
            description: $description,
            canonicalUrl: $canonicalUrl,
            ogImage: $this->seoOgImageOverride,
            noIndex: $this->status !== PageStatus::Published,
            hreflangs: $hreflangs,
        );
    }
}
