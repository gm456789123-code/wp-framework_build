<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class SeoData
{
    public function __construct(
        public string $title,
        public string $description = '',
        public ?string $canonicalUrl = null,
        public ?string $ogImage = null,
        public bool $noIndex = false,
    ) {
    }

    /**
     * @return array{title: string, description: string, canonicalUrl: ?string, ogImage: ?string, noIndex: bool}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonicalUrl' => $this->canonicalUrl,
            'ogImage' => $this->ogImage,
            'noIndex' => $this->noIndex,
        ];
    }
}
