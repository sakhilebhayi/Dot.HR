<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response (R-32 Phase 2 — Dot.HR previously sent
 * none at all, the last of the fleet's gaps from the R-22 census). A header the route
 * already set is left alone, so a stricter route-level value always wins (the document
 * download sets its own sandboxed policy).
 *
 * The Content-Security-Policy is ENFORCED and nonce-based, the same shape InfoDot runs
 * live (R-19): a per-request nonce is registered with Vite, so @vite tags and Livewire's
 * assets carry it automatically (Livewire's FrontendAssets falls back to Vite::cspNonce()),
 * and the three hand-written inline scripts declare it. 'unsafe-inline' is gone from
 * script-src and script-src-attr is 'none': the views' onsubmit=/onclick=/onchange=
 * handlers became data-confirm / data-theme-toggle / data-autosubmit, handled in app.js.
 * 'unsafe-eval' REMAINS as a stack requirement: Livewire 3's bundled Alpine evaluates its
 * expressions with the Function constructor. style-src keeps 'unsafe-inline' because The
 * Register's views use style attributes throughout; script injection is the risk here.
 *
 * Violations report to /csp-report. SECURITY_CSP_REPORT_ONLY=true in .env is the
 * kill-switch back to report-only without a deploy.
 *
 * Strict-Transport-Security goes on HTTPS responses, 180 days by default (the host
 * already redirects http to https). It is max-age only: sibling subdomains set their own.
 * SECURITY_HSTS_SECONDS=0 turns it off.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Registered before the views render so every @vite tag, Livewire asset and
        // nonce-marked inline script carries the value the header announces.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Reporting-Endpoints' => 'csp="'.url('/csp-report').'"',
        ];

        $headers[config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->policy($nonce);

        $hstsSeconds = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hstsSeconds > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hstsSeconds;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // Local dev loads assets from the Vite server (http: and its ws: hot reload).
        $dev = app()->isLocal() ? ' http://localhost:* http://127.0.0.1:*' : '';

        return "default-src 'self'; "
            ."script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$dev}; "
            ."script-src-attr 'none'; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$dev}; "
            ."font-src 'self' data: https://fonts.gstatic.com; "
            // The landing page's photograph is served by Unsplash (as on InfoDot).
            ."img-src 'self' data: blob: https://images.unsplash.com; "
            ."connect-src 'self' wss: https:".(app()->isLocal() ? ' ws: http:' : '').'; '
            ."frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; "
            ."form-action 'self' https:; report-uri /csp-report; report-to csp";
    }
}
