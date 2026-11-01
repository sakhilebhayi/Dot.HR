<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports (the same endpoint as InfoDot), so anything the enforced policy blocks on a real
 * page shows up in the log instead of failing silently. It keeps almost nothing: the directive and the blocked HOST (never the full address, which can carry
 * tokens in its query), logged at most once per pair per day. The body is limited and never echoed.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = substr((string) $request->getContent(), 0, 8192);
        $json = json_decode($raw, true);
        // Two shapes exist: {"csp-report": {...}} (report-uri) and [{"type": "csp-violation", "body": {...}}] (Reporting API).
        $reports = is_array($json) ? (isset($json['csp-report']) ? [$json['csp-report']] : (array_is_list($json) ? array_map(fn ($r) => is_array($r) ? ($r['body'] ?? []) : [], $json) : [])) : [];

        foreach (array_slice($reports, 0, 5) as $r) {
            if (! is_array($r)) {
                continue;
            }

            // The payload is attacker-controlled: a field may be an array (a string cast would throw
            // under Laravel's warning handler) and a forged host could be arbitrarily long (the
            // once-a-day cache key must stay within the cache store's key limits). R-22 hardening.
            $directive = substr(preg_replace('/[^a-z-]/', '', strtolower($this->firstString($r, ['effective-directive', 'effectiveDirective', 'violated-directive']))), 0, 40);
            $blocked = $this->firstString($r, ['blocked-uri', 'blockedURL']);
            $host = in_array($blocked, ['inline', 'eval', 'self', ''], true) ? ($blocked ?: 'none') : (strtolower((string) parse_url($blocked, PHP_URL_HOST)) ?: 'other');
            $host = substr(preg_replace('/[^a-z0-9.\-]/', '', $host), 0, 100);

            if ($directive !== '' && Cache::add("csp:$directive:$host:".now()->format('Y-m-d'), 1, 90000)) {
                Log::info('csp.violation', ['directive' => $directive, 'blocked_host' => $host]);
            }
        }

        return response()->noContent();
    }

    /** The first of the named keys whose value is a non-empty string; anything else is ignored. */
    private function firstString(array $report, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($report[$key]) && is_string($report[$key]) && $report[$key] !== '') {
                return $report[$key];
            }
        }

        return '';
    }
}
