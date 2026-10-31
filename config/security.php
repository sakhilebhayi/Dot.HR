<?php

// Browser security headers (see App\Http\Middleware\SecurityHeaders).
return [
    // Strict-Transport-Security max-age in seconds; 0 leaves the header off.
    // Set SECURITY_HSTS_SECONDS once the host is confirmed HTTPS-only.
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 0),
];
