<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Hiring\ApplicationController;
use App\Http\Controllers\Hiring\VacancyController;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\JobBoardController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LifecycleController;
use App\Http\Controllers\Me\MyApplicationsController;
use App\Http\Controllers\Me\ProfessionalProfileController;
use App\Http\Controllers\MyWorkController;
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

    // The employee lifecycle (R-32 Phase 5): HR's checklists and request inbox...
    Route::get('/lifecycle', [LifecycleController::class, 'index'])->name('lifecycle.index');
    Route::get('/lifecycle/settings', [LifecycleController::class, 'editTemplates'])->name('lifecycle.templates');
    Route::put('/lifecycle/settings/{process}', [LifecycleController::class, 'updateTemplate'])->whereIn('process', ['onboarding', 'offboarding'])->name('lifecycle.templates.update');
    Route::delete('/lifecycle/settings/{process}', [LifecycleController::class, 'resetTemplate'])->whereIn('process', ['onboarding', 'offboarding'])->name('lifecycle.templates.reset');
    Route::post('/employees/{employee}/lifecycle/{process}', [LifecycleController::class, 'start'])->whereIn('process', ['onboarding', 'offboarding'])->name('lifecycle.start');
    Route::get('/lifecycle/{checklist}', [LifecycleController::class, 'show'])->name('lifecycle.show');
    Route::post('/lifecycle/{checklist}/finish', [LifecycleController::class, 'finish'])->name('lifecycle.finish');
    Route::post('/lifecycle/{checklist}/cancel', [LifecycleController::class, 'cancel'])->name('lifecycle.cancel');
    Route::post('/lifecycle/tasks/{task}', [LifecycleController::class, 'toggle'])->name('lifecycle.tasks.toggle');

    Route::get('/requests', [HrRequestController::class, 'index'])->name('requests.index');
    Route::get('/requests/{hrRequest}', [HrRequestController::class, 'show'])->name('requests.show');
    Route::post('/requests/{hrRequest}/answer', [HrRequestController::class, 'answer'])->name('requests.answer');
    Route::post('/requests/{hrRequest}/close', [HrRequestController::class, 'close'])->name('requests.close');

    // ...and the employee's own self-service in the current business. No employee id in
    // any route: everything acts on the record linked to the signed-in account.
    Route::prefix('my-work')->name('my-work.')->controller(MyWorkController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/details', 'updateDetails')->middleware('throttle:30,1')->name('details');
        Route::post('/tasks/{task}', 'completeTask')->name('tasks.complete');
        Route::post('/requests', 'storeRequest')->middleware('throttle:10,1')->name('requests.store');
        Route::get('/requests/{hrRequest}', 'showRequest')->name('requests.show');
    });

    // Hiring (R-32 Phase 4): the business's roles and pipeline. Route-bound Vacancy and
    // Application are team-scoped, so another business's records answer 404.
    Route::prefix('hiring')->name('hiring.')->group(function () {
        Route::get('/', [VacancyController::class, 'index'])->name('index');
        Route::get('/roles/new', [VacancyController::class, 'create'])->name('create');
        Route::post('/roles', [VacancyController::class, 'store'])->name('store');
        Route::get('/roles/{vacancy}', [VacancyController::class, 'show'])->name('show');
        Route::get('/roles/{vacancy}/edit', [VacancyController::class, 'edit'])->name('edit');
        Route::put('/roles/{vacancy}', [VacancyController::class, 'update'])->name('update');
        Route::post('/roles/{vacancy}/duplicate', [VacancyController::class, 'duplicate'])->name('duplicate');
        Route::post('/roles/{vacancy}/{to}', [VacancyController::class, 'transition'])
            ->whereIn('to', ['publish', 'pause', 'close', 'reopen'])->name('transition');

        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::post('/applications/{application}/stage', [ApplicationController::class, 'move'])->name('applications.move');
        Route::post('/applications/{application}/notes', [ApplicationController::class, 'note'])->name('applications.note');
        Route::post('/applications/{application}/interviews', [ApplicationController::class, 'scheduleInterview'])->name('applications.interviews.store');
        Route::post('/applications/{application}/interviews/{interview}/cancel', [ApplicationController::class, 'cancelInterview'])->whereNumber('interview')->name('applications.interviews.cancel');
        Route::post('/applications/{application}/interviews/{interview}/feedback', [ApplicationController::class, 'feedback'])->whereNumber('interview')->name('applications.interviews.feedback');
        Route::post('/applications/{application}/offers', [ApplicationController::class, 'offer'])->name('applications.offers.store');
        Route::post('/applications/{application}/offers/{offer}/send', [ApplicationController::class, 'sendOffer'])->whereNumber('offer')->name('applications.offers.send');
        Route::post('/applications/{application}/offers/{offer}/withdraw', [ApplicationController::class, 'withdrawOffer'])->whereNumber('offer')->name('applications.offers.withdraw');
    });

    // Applying, and the person's own applications. Ids only ever resolve to the signed-in
    // person's own rows (MyApplicationsController::mine()).
    Route::post('/jobs/{vacancy}/apply', [JobBoardController::class, 'apply'])
        ->whereNumber('vacancy')->middleware('throttle:20,1')->name('jobs.apply');
    Route::prefix('me/applications')->name('me.applications.')->controller(MyApplicationsController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{application}', 'show')->whereNumber('application')->name('show');
        Route::post('/{application}/withdraw', 'withdraw')->whereNumber('application')->name('withdraw');
        Route::post('/{application}/offer/{decision}', 'respond')->whereNumber('application')
            ->whereIn('decision', ['accept', 'decline'])->middleware('throttle:20,1')->name('respond');
    });

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

// The public job board: open roles from every business, browsable without an account.
Route::get('/jobs', [JobBoardController::class, 'index'])->middleware('throttle:120,1')->name('jobs.index');
Route::get('/jobs/{vacancy}', [JobBoardController::class, 'show'])->whereNumber('vacancy')->middleware('throttle:120,1')->name('jobs.show');

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
