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

        return <<<HTML
<div id="sovereign-devtoolbar" style="position:fixed;bottom:0;left:0;right:0;z-index:99998;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;gap:16px;padding:8px 16px;font-family:monospace;font-size:12px;border-top:1px solid #334155;box-shadow:0 -4px 6px -1px rgba(0,0,0,0.1);">
    <span style="font-weight:bold;color:#38bdf8;">⚡ Sovereign DevToolbar</span>
    <span>Queries: <strong style="color:{$queryBadgeColor};">{$queryCount}</strong> {if $queryCount > 3}(Budget: &le; 3){/if}</span>
    <span>Cache: <strong style="color:{$cacheBadgeColor};">{$cacheStatus}</strong></span>
    <span>TTFB: <strong>{$durationMs}ms</strong></span>
    <span>Memory: <strong>{$memMb} MB</strong></span>
</div>
HTML;
    }
}
