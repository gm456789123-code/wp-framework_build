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
            default => 'raw.latte',
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
}
