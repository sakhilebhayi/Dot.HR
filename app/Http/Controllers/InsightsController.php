<?php

namespace App\Http\Controllers;

use App\Services\Hr\Audit;
use App\Services\Intelligence\Insights;
use App\Services\Intelligence\InsightsExport;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The business's own numbers and skills picture (R-32 Phase 7), for HR administrators. Every
 * figure comes from records in Dot.HR and says how it is worked out.
 */
class InsightsController extends Controller
{
    public function index(Request $request, Insights $insights): View
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return view('insights.index', [
            'workforce' => $insights->workforce(),
            'recruitment' => $insights->recruitment(),
            'leave' => $insights->leave(),
            'development' => $insights->development(),
            'contractors' => $insights->contractors(),
            'coverage' => $insights->coverage($request->user()),
            'team' => $access->team(),
        ]);
    }

    /** The page's figures as CSV, the whole page or one section. Audited; HR administrators only. */
    public function export(Request $request, InsightsExport $export): StreamedResponse
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);
        $section = (string) $request->query('section', 'all');
        abort_unless(array_key_exists($section, InsightsExport::SECTIONS), 404);

        $rows = $export->rows($section, $request->user());
        Audit::record('insights.exported', $access->team(), [], ['section' => $section, 'rows' => count($rows) - 1], $access->team()->id);

        $name = 'dot-hr-insights-'.($section === 'all' ? '' : $section.'-').Str::slug($access->team()->name).'-'.LocalTime::today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows) {
            echo "\u{FEFF}"; // so spreadsheet apps read the file as UTF-8
            foreach ($rows as $row) {
                echo InsightsExport::line($row);
            }
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
