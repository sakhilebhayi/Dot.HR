<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\Me\ProfessionalProfileController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\WorkspaceAccessController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

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

    // Business workspace: who among the company's members may recruit (R-32 Phase 3).
    Route::get('/access', [WorkspaceAccessController::class, 'index'])->name('access.index');
    Route::post('/access', [WorkspaceAccessController::class, 'store'])->name('access.store');
    Route::delete('/access/{grant}', [WorkspaceAccessController::class, 'destroy'])->name('access.destroy');

    // Personal workspace: the person's own career space, independent of any company.
    // No id in any of these routes — they only ever act on the signed-in person.
    Route::prefix('me')->name('me.')->controller(ProfessionalProfileController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/profile', 'edit')->name('profile');
        Route::put('/profile', 'update')->name('profile.update');
        Route::post('/profile/skills', 'addSkill')->middleware('throttle:60,1')->name('skills.store');
        Route::delete('/profile/skills/{skill}', 'removeSkill')->whereNumber('skill')->name('skills.destroy');
        Route::post('/profile/entries', 'addEntry')->middleware('throttle:60,1')->name('entries.store');
        Route::delete('/profile/entries/{entry}', 'removeEntry')->whereNumber('entry')->name('entries.destroy');
        Route::post('/profile/blocks', 'block')->middleware('throttle:20,1')->name('blocks.store');
        Route::delete('/profile/blocks/{team}', 'unblock')->whereNumber('team')->name('blocks.destroy');
    });
});

// A professional profile's share link. Who sees what is decided by ProfessionalProfilePolicy:
// a profile the viewer may not see answers 404, exactly like one that does not exist.
Route::get('/people/{handle}', [ProfessionalProfileController::class, 'show'])
    ->where('handle', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('people.show');

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
