<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class SeoData
{
    /**
     * @param array<string, string> $hreflangs
     */
    public function __construct(
        public string $title,
        public string $description = '',
        public ?string $canonicalUrl = null,
        public ?string $ogImage = null,
        public bool $noIndex = false,
        public array $hreflangs = [],
    ) {
    }

    /**
     * @return array{title: string, description: string, canonicalUrl: ?string, ogImage: ?string, noIndex: bool, hreflangs: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonicalUrl' => $this->canonicalUrl,
            'ogImage' => $this->ogImage,
            'noIndex' => $this->noIndex,
            'hreflangs' => $this->hreflangs,
        ];
    }
}
