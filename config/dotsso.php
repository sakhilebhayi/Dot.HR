<?php

use App\Sso\HrProvisioner;

/*
 * Dot.HR's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env. The session guard is the package
 * default (`web`), which matters here: the signed-in routes use `auth:sanctum`.
 */
return [
    'provisioner' => HrProvisioner::class,

    // HR has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link is only ever a same-origin path; it may open any signed-in page (a vacancy page the person wanted to apply
    // to, their applications, an employee record). Sign-in, machine and public-probe doors can never be named.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|api|csp-report|ready|up)(/|\?|$)#i'],
    ],

    // Nothing extra is exempt. The public job board (/jobs, /jobs/{vacancy}), the public profile share link (/people/{handle}),
    // /ready, /csp-report and Sanctum /api/* are never intercepted: the package only touches the login, register and reset pages.
    'allow_paths' => [],
];
