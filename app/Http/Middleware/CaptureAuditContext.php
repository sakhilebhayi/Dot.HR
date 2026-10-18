<?php

namespace App\Http\Middleware;

use App\Services\Hr\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the audit log the client IP of the web request being served. Console work (the
 * purge, seeders, tinker) never passes through here, so its rows carry no IP, rather than
 * the 127.0.0.1 a synthetic console request would claim. The IP is what Laravel resolves
 * for the request: behind a proxy, configure trusted proxies or it will be the proxy's.
 */
class CaptureAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Audit::useRequestIp($request->ip());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        Audit::useRequestIp(null);
    }
}
