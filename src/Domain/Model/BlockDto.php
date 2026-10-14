<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class BlockDto
{
    /**
     * @param array<string, mixed> $attrs
     * @param list<BlockDto> $innerBlocks
     */
    public function __construct(
        public string $blockName,
        public array $attrs = [],
        public string $innerHTML = '',
        public array $innerBlocks = [],
    ) {
    }
}
