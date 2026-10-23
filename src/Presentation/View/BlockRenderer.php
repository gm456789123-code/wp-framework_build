<?php

declare(strict_types=1);

namespace App\Presentation\View;

use App\Domain\Model\BlockDto;
use Latte\Engine;

final readonly class BlockRenderer
{
    public function __construct(
        private Engine $latte,
        private string $templateDir,
    ) {
    }

    /**
     * @param list<BlockDto> $blocks
     */
    public function renderAll(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->render($block);
        }

        return $html;
    }

    public function render(BlockDto $block): string
    {
        $innerContent = '';
        if (!empty($block->innerBlocks)) {
            $innerContent = $this->renderAll($block->innerBlocks);
        }

        $templateFile = match ($block->blockName) {
            'core/paragraph' => 'paragraph.latte',
            'core/heading' => 'heading.latte',
            'core/columns' => 'columns.latte',
            'core/image' => 'image.latte',
            default => $this->resolveConventionalTemplate($block->blockName),
        };

        $fullPath = rtrim($this->templateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $templateFile;
        if (!is_file($fullPath)) {
            return $block->innerHTML !== '' ? $block->innerHTML : $innerContent;
        }

        return $this->latte->renderToString($fullPath, [
            'block' => $block,
            'attrs' => $block->attrs,
            'innerHTML' => $block->innerHTML,
            'innerContent' => $innerContent,
        ]);
    }

    /**
     * Any block name that isn't one of the built-in core/* cases above is
     * resolved by convention from `make:block`'s own naming: the slug after
     * the last "/" (e.g. "acf/hero-banner" or "theme/hero-banner" both
     * resolve to "hero-banner.latte"), so a freshly scaffolded template is
     * picked up automatically with no second file to edit. Falls back to
     * raw.latte when no matching template exists on disk.
     */
    private function resolveConventionalTemplate(string $blockName): string
    {
        $slashPosition = strrpos($blockName, '/');
        $slug = $slashPosition !== false ? substr($blockName, $slashPosition + 1) : $blockName;
        $slug = strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $slug));
        $slug = trim($slug, '-');

        if ($slug === '') {
            return 'raw.latte';
        }

        $candidate = "{$slug}.latte";
        $candidatePath = rtrim($this->templateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;

        return is_file($candidatePath) ? $candidate : 'raw.latte';
    }
}
