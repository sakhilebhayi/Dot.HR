<?php

namespace App\Http\Controllers;

use App\Services\Intelligence\Insights;
use App\Support\Hr\HrAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
}
