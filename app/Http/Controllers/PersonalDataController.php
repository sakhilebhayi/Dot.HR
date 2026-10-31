<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Hr\Audit;
use App\Services\Privacy\PersonalDataExport;
use App\Support\Hr\HrAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Access to personal information (R-32 Phase 8, POPIA s23): the person downloads what a
 * business holds about them (My work) or what they keep themselves (My career); an HR
 * administrator produces the business's file to answer a formal request. Every export is
 * audited — that it happened and for whom, never what was in it.
 */
class PersonalDataController extends Controller
{
    public function __construct(private PersonalDataExport $export) {}

    public function mine(Request $request): JsonResponse
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        Audit::record('privacy.exported', $employee, ['by_self']);

        return $this->download($this->export->forEmployee($employee, forHr: false), 'my-data-'.Str::slug($employee->team->name));
    }

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(HrAccess::current($request->user())?->isAdmin(), 403);

        Audit::record('privacy.exported', $employee, ['by_hr']);

        return $this->download($this->export->forEmployee($employee, forHr: true), 'personal-data-'.Str::slug($employee->fullName()));
    }

    public function career(Request $request): JsonResponse
    {
        return $this->download($this->export->forUser($request->user()), 'my-dot-hr-career-data');
    }

    /** @param array<string, mixed> $data */
    private function download(array $data, string $name): JsonResponse
    {
        return response()
            ->json($data, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="'.$name.'-'.now()->format('Y-m-d').'.json"')
            ->header('Cache-Control', 'no-store');
    }
}
