<?php

// Browser security headers (see App\Http\Middleware\SecurityHeaders).
return [
    // The Content-Security-Policy is enforced. Set SECURITY_CSP_REPORT_ONLY=true to fall back
    // to report-only without a deploy; either way violations keep logging through /csp-report.
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    // Strict-Transport-Security max-age in seconds, sent on HTTPS responses only; 0 leaves it
    // off. 180 days, as on InfoDot. Subdomains are not included: siblings set their own.
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),
];
