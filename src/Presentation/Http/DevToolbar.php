<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final readonly class DevToolbar
{
    public function render(int $queryCount, float $startTimeMs, bool $cacheHit = false): string
    {
        $durationMs = round((microtime(true) * 1000) - $startTimeMs, 1);
        $memMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $queryBadgeColor = $queryCount > 3 ? '#ef4444' : '#10b981';
        $cacheBadgeColor = $cacheHit ? '#10b981' : '#f59e0b';
        $cacheStatus = $cacheHit ? 'HIT (0 DB)' : 'MISS';

        $budgetWarning = $queryCount > 3 ? ' <span style="color:#ef4444;">(Budget: &le; 3)</span>' : '';

        return <<<HTML
<div id="sovereign-devtoolbar" style="position:fixed;bottom:0;left:0;right:0;z-index:99998;background:#09090b;color:#a1a1aa;display:flex;align-items:center;gap:16px;padding:8px 20px;font-family:monospace;font-size:11px;border-top:1px solid #27272a;box-shadow:0 -4px 12px rgba(0,0,0,0.5);">
    <span style="font-weight:600;color:#38bdf8;display:flex;align-items:center;gap:6px;">⚡ Sovereign DevToolbar</span>
    <span>Queries: <strong style="color:{$queryBadgeColor};">{$queryCount}</strong>{$budgetWarning}</span>
    <span>Cache: <strong style="color:{$cacheBadgeColor};">{$cacheStatus}</strong></span>
    <span>TTFB: <strong style="color:#f4f4f5;">{$durationMs}ms</strong></span>
    <span>Memory: <strong style="color:#f4f4f5;">{$memMb} MB</strong></span>
</div>
HTML;
    }
}
