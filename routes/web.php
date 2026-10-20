<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\PositionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])
        ->withTrashed()
        ->name('employees.restore');
    Route::post('/employees/{employee}/link', [EmployeeController::class, 'link'])->name('employees.link');
    Route::delete('/employees/{employee}/link', [EmployeeController::class, 'unlink'])->name('employees.unlink');
    Route::resource('employees', EmployeeController::class);

    Route::resource('leave-requests', LeaveRequestController::class)
        ->except(['edit', 'update']);
    Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
        ->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/deny', [LeaveRequestController::class, 'deny'])
        ->name('leave-requests.deny');
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
        ->name('leave-requests.cancel');

    Route::resource('positions', PositionController::class)->except(['show']);
    Route::resource('leave-types', LeaveTypeController::class)->except(['show']);
    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
});
