<?php

declare(strict_types=1);

namespace App\Infrastructure\Wordpress\Gutenberg;

use App\Domain\Model\BlockDto;
use PDO;

final readonly class BlockAstParser
{
    public function __construct(
        private string $appUrl = '',
    ) {
    }

    /**
     * Parses raw post_content Gutenberg string into typed BlockDto array.
     *
     * @return list<BlockDto>
     */
    public function parse(string $content): array
    {
        $content = $this->rewriteMediaUrls($content);
        $pattern = '/<!--\s+(\/)?wp:([a-zA-Z0-9\/\-_]+)(?:\s+(\{.*?\}))?\s*(\/)?-->/s';

        /** @var list<array{type: string, name: string, attrs: array<string, mixed>, pos: int, length: int}> $tokens */
        $tokens = [];

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $isClose = $match[1][0] === '/';
                $name = $match[2][0];
                $name = str_contains($name, '/') ? $name : 'core/' . $name;
                $attrsJson = isset($match[3]) ? $match[3][0] : '{}';
                $isSelfClosing = isset($match[4]);

                /** @var mixed $rawAttrs */
                $rawAttrs = json_decode($attrsJson, true);
                /** @var array<string, mixed> $attrs */
                $attrs = is_array($rawAttrs) ? $rawAttrs : [];

                $type = $isClose ? 'close' : ($isSelfClosing ? 'self_closing' : 'open');
                $fullTag = $match[0][0];
                $pos = $match[0][1];

                $tokens[] = [
                    'type' => $type,
                    'name' => $name,
                    'attrs' => $attrs,
                    'pos' => $pos,
                    'length' => strlen($fullTag),
                ];
            }
        }

        if (empty($tokens)) {
            $trimmed = trim($content);
            if ($trimmed === '') {
                return [];
            }

            return [new BlockDto(
                blockName: 'core/freeform',
                attrs: [],
                innerHTML: $trimmed,
                innerBlocks: [],
            )];
        }

        return $this->buildTree($content, $tokens);
    }

    /**
     * Eager pre-resolution for synced patterns (Reusable Blocks: <!-- wp:block {"ref":123} -->).
     * Single Query Batch: SELECT ID, post_content FROM wp_posts WHERE ID IN (...).
     * Guarantees zero N+1 queries.
     *
     * @param list<BlockDto> $blocks
     * @return list<BlockDto>
     */
    public function resolveSyncedPatterns(array $blocks, PDO $pdo): array
    {
        $refs = [];
        $this->collectPatternRefs($blocks, $refs);

        if (empty($refs)) {
            return $blocks;
        }

        $uniqueRefs = array_values(array_unique($refs));
        $placeholders = implode(',', array_fill(0, count($uniqueRefs), '?'));

        $stmt = $pdo->prepare(
            "SELECT ID, post_content FROM wp_posts WHERE ID IN ($placeholders) AND deleted_at IS NULL"
        );
        $stmt->execute($uniqueRefs);

        /** @var list<array{ID: int|numeric-string, post_content: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<int, string> $resolvedContent */
        $resolvedContent = [];
        foreach ($rows as $row) {
            $resolvedContent[(int) $row['ID']] = $row['post_content'];
        }

        /** @var array<int, list<BlockDto>> $resolvedBlocks */
        $resolvedBlocks = [];
        foreach ($resolvedContent as $refId => $raw) {
            $resolvedBlocks[$refId] = $this->parse($raw);
        }

        return $this->injectResolvedPatterns($blocks, $resolvedBlocks);
    }

    /**
     * @param list<array{type: string, name: string, attrs: array<string, mixed>, pos: int, length: int}> $tokens
     * @return list<BlockDto>
     */
    private function buildTree(string $content, array $tokens): array
    {
        /** @var list<BlockDto> $result */
        $result = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token['type'] === 'self_closing') {
                $result[] = new BlockDto(
                    blockName: $token['name'],
                    attrs: $token['attrs'],
                    innerHTML: '',
                    innerBlocks: [],
                );
                $i++;
                continue;
            }

            if ($token['type'] === 'open') {
                $blockName = $token['name'];
                $blockAttrs = $token['attrs'];
                $contentStart = $token['pos'] + $token['length'];

                // Find matching close tag
                $depth = 1;
                $j = $i + 1;
                while ($j < $count) {
                    if ($tokens[$j]['name'] === $blockName) {
                        if ($tokens[$j]['type'] === 'open') {
                            $depth++;
                        } elseif ($tokens[$j]['type'] === 'close') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        }
                    }
                    $j++;
                }

                if ($j < $count && $depth === 0) {
                    $contentEnd = $tokens[$j]['pos'];
                    $innerHtml = substr($content, $contentStart, $contentEnd - $contentStart);

                    // Check for nested tokens inside this range
                    $nestedTokens = array_slice($tokens, $i + 1, $j - $i - 1);
                    $innerBlocks = !empty($nestedTokens) ? $this->buildTree($content, $nestedTokens) : [];

                    $result[] = new BlockDto(
                        blockName: $blockName,
                        attrs: $blockAttrs,
                        innerHTML: trim($innerHtml),
                        innerBlocks: $innerBlocks,
                    );

                    $i = $j + 1;
                    continue;
                }

                // Unclosed block fallback
                $result[] = new BlockDto(
                    blockName: $blockName,
                    attrs: $blockAttrs,
                    innerHTML: '',
                    innerBlocks: [],
                );
            }

            $i++;
        }

        return $result;
    }

    /**
     * @param list<BlockDto> $blocks
     * @param list<int> $refs
     */
    private function collectPatternRefs(array $blocks, array &$refs): void
    {
        foreach ($blocks as $block) {
            if ($block->blockName === 'core/block' && isset($block->attrs['ref']) && is_numeric($block->attrs['ref'])) {
                $refs[] = (int) $block->attrs['ref'];
            }
            if (!empty($block->innerBlocks)) {
                $this->collectPatternRefs($block->innerBlocks, $refs);
            }
        }
    }

    /**
     * @param list<BlockDto> $blocks
     * @param array<int, list<BlockDto>> $resolvedBlocks
     * @return list<BlockDto>
     */
    private function injectResolvedPatterns(array $blocks, array $resolvedBlocks): array
    {
        /** @var list<BlockDto> $flattened */
        $flattened = [];

        foreach ($blocks as $block) {
            if ($block->blockName === 'core/block' && isset($block->attrs['ref']) && is_numeric($block->attrs['ref'])) {
                $refId = (int) $block->attrs['ref'];
                if (isset($resolvedBlocks[$refId])) {
                    foreach ($resolvedBlocks[$refId] as $child) {
                        $flattened[] = $child;
                    }
                    continue;
                }
            }

            if (!empty($block->innerBlocks)) {
                $updatedChildren = $this->injectResolvedPatterns($block->innerBlocks, $resolvedBlocks);
                $flattened[] = new BlockDto(
                    blockName: $block->blockName,
                    attrs: $block->attrs,
                    innerHTML: $block->innerHTML,
                    innerBlocks: $updatedChildren,
                );
            } else {
                $flattened[] = $block;
            }
        }

        return $flattened;
    }

    private function rewriteMediaUrls(string $content): string
    {
        // Replace legacy /wp-content/uploads/ with /storage/
        $rewritten = (string) preg_replace(
            '/https?:\/\/[^\/"]+\/wp-content\/uploads\//i',
            '/storage/',
            $content
        );

        if ($this->appUrl !== '') {
            $origin = rtrim($this->appUrl, '/');
            $rewritten = (string) preg_replace(
                '/(src|href)=["\'](\/storage\/[^"\']+)["\']/i',
                '$1="' . $origin . '$2"',
                $rewritten
            );
        }

        return $rewritten;
    }
}
