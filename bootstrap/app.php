<?php

use App\Http\Middleware\CaptureAuditContext;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Sent by the browser, not by a form: it carries no session and changes nothing.
        $middleware->validateCsrfTokens(except: ['csp-report']);
        // R-50: after the session has started, so the signed-in person and their current company are known.
        $middleware->web(append: [CaptureAuditContext::class, EnsureCompanyEntitled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
