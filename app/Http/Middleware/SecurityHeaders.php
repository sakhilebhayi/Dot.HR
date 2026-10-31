<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response (R-32 Phase 2 — Dot.HR previously sent
 * none at all, the last of the fleet's gaps from the R-22 census). A header the route
 * already set is left alone, so a stricter route-level value always wins.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 * Permissions-Policy and (HTTPS only, when configured) Strict-Transport-Security.
 * HSTS is max-age only: sibling subdomains are not all ready for includeSubDomains.
 * SECURITY_HSTS_SECONDS in .env sets the age; 0 (the default) leaves it off.
 *
 * The Content-Security-Policy is REPORT-ONLY and blocks nothing yet: Livewire and
 * Jetstream need inline scripts and 'unsafe-eval' (Alpine evaluates expressions with
 * the Function constructor). The outside origins listed are the ones the layouts load
 * today (Google Fonts). Enforcing comes later, from what the reports show.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

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

    private function policy(): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            ."font-src 'self' data: https://fonts.gstatic.com; "
            ."img-src 'self' data: blob:; "
            ."connect-src 'self' wss: https:".(app()->isLocal() ? ' ws: http:' : '').'; '
            ."frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'";
    }
}
