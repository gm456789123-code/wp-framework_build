<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final readonly class CspBridge
{
    /**
     * @param list<string> $whitelistedDomains
     */
    public function __construct(
        private array $whitelistedDomains = [
            'https://www.googletagmanager.com',
            'https://www.google-analytics.com',
        ],
    ) {
    }

    public function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    public function buildHeader(string $nonce): string
    {
        $scripts = implode(' ', array_merge(
            ["'self'", "'nonce-{$nonce}'"],
            $this->whitelistedDomains,
        ));

        return sprintf(
            "default-src 'self'; script-src %s; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self' https://www.google-analytics.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; report-uri /api/v1/telemetry/csp-reports;",
            $scripts
        );
    }

    /**
     * Injects diagnostic observer modal script for Admin/Preview sessions.
     */
    public function renderDiagnosticObserverScript(string $nonce): string
    {
        return <<<HTML
<script nonce="{$nonce}">
document.addEventListener('securitypolicyviolation', (e) => {
    console.warn('[CSP Violation]', e.blockedURI);
    let modal = document.getElementById('sovereign-csp-diagnostic');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sovereign-csp-diagnostic';
        modal.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;background:#1e293b;color:#f8fafc;padding:16px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.3);max-width:380px;font-family:sans-serif;font-size:13px;border-left:4px solid #ef4444;';
        document.body.appendChild(modal);
    }
    modal.innerHTML = `
        <div style="font-weight:bold;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;">
            <span>🛡️ CSP Blocked Domain</span>
            <span style="cursor:pointer;color:#94a3b8;" onclick="this.parentElement.parentElement.remove()">✕</span>
        </div>
        <div style="word-break:break-all;color:#e2e8f0;margin-bottom:8px;">\${e.blockedURI}</div>
        <button style="background:#3b82f6;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;" onclick="navigator.clipboard.writeText('\${e.blockedURI}');this.innerText='Copied!';">Copy Domain</button>
    `;
});
</script>
HTML;
    }
}
