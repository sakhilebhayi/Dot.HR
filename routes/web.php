<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\CertificationController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\Hiring\ApplicationController;
use App\Http\Controllers\Hiring\CandidateController;
use App\Http\Controllers\Hiring\HiringSetupController;
use App\Http\Controllers\Hiring\InternalTalentController;
use App\Http\Controllers\Hiring\VacancyController;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\JobBoardController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LeavingController;
use App\Http\Controllers\LifecycleController;
use App\Http\Controllers\Me\MyApplicationsController;
use App\Http\Controllers\Me\ProfessionalProfileController;
use App\Http\Controllers\MyCaseController;
use App\Http\Controllers\MyCompetencyController;
use App\Http\Controllers\MyContractorController;
use App\Http\Controllers\MyLearningController;
use App\Http\Controllers\MyLeavingController;
use App\Http\Controllers\MyPlanController;
use App\Http\Controllers\MySwapController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\PersonalDataController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ReadyController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SwapController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TrainingController;
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
    // A ceiling for every signed-in request, per person (R-32 Phase 8); routes that write
    // or send keep their own tighter limits on top.
    'throttle:hr',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])
        ->withTrashed()
        ->name('employees.restore');
    Route::post('/employees/{employee}/link', [EmployeeController::class, 'link'])->name('employees.link');
    Route::delete('/employees/{employee}/link', [EmployeeController::class, 'unlink'])->name('employees.unlink');
    Route::resource('employees', EmployeeController::class);
    // Employment documents and company policies (R-32 §10).
    Route::post('/employees/{employee}/documents', [DocumentController::class, 'store'])->middleware('throttle:20,1')->name('employees.documents.store');
    Route::post('/documents/{document}/share', [DocumentController::class, 'share'])->name('documents.share');
    Route::post('/documents/{document}/withdraw', [DocumentController::class, 'withdraw'])->name('documents.withdraw');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->middleware('throttle:60,1')->name('documents.download');
    Route::post('/documents/{document}/acknowledge', [DocumentController::class, 'acknowledge'])->middleware('throttle:20,1')->name('documents.acknowledge');
    Route::post('/my-work/documents', [DocumentController::class, 'submit'])->middleware('throttle:10,1')->name('my-work.documents.store');
    Route::get('/policies', [DocumentController::class, 'policies'])->name('policies.index');
    Route::post('/policies', [DocumentController::class, 'storePolicy'])->middleware('throttle:10,1')->name('policies.store');
    Route::get('/employees/{employee}/personal-data', [PersonalDataController::class, 'employee'])->middleware('throttle:10,1')->name('employees.personal-data');

    // Employee relations (brief §15): grievances, disciplinary cases, incidents. HR
    // administrators and employee relations holders; the person's own side under My work.
    Route::prefix('cases')->name('cases.')->controller(CaseController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
        Route::get('/{case}', 'show')->name('show');
        Route::post('/{case}/entries', 'entry')->middleware('throttle:60,1')->name('entries.store');
        Route::post('/{case}/investigate', 'investigate')->name('investigate');
        Route::post('/{case}/hearing', 'hearing')->name('hearing');
        Route::post('/{case}/decide', 'decide')->name('decide');
        Route::post('/{case}/appeal', 'appeal')->name('appeal');
        Route::post('/{case}/appeal-decision', 'appealDecision')->name('appeal-decision');
        Route::post('/{case}/close', 'close')->name('close');
        Route::post('/{case}/withdraw', 'withdraw')->name('withdraw');
        Route::post('/{case}/assign', 'assign')->name('assign');
        Route::post('/{case}/reported', 'reported')->name('reported');
    });
    // Performance improvement plans: HR administrators and the named supporting manager; the
    // person's own side under My work.
    Route::prefix('plans')->name('plans.')->controller(PlanController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
        Route::get('/{plan}', 'show')->name('show');
        Route::get('/{plan}/edit', 'edit')->name('edit');
        Route::put('/{plan}', 'update')->name('update');
        Route::post('/{plan}/share', 'share')->name('share');
        Route::post('/{plan}/check-ins', 'checkIn')->middleware('throttle:30,1')->name('check-ins.store');
        Route::post('/{plan}/extend', 'extend')->name('extend');
        Route::post('/{plan}/complete', 'complete')->name('complete');
        Route::post('/{plan}/cancel', 'cancel')->name('cancel');
    });
    Route::prefix('my-work/plans')->name('my-work.plans.')->controller(MyPlanController::class)->group(function () {
        Route::get('/{plan}', 'show')->name('show');
        Route::post('/{plan}/acknowledge', 'acknowledge')->middleware('throttle:10,1')->name('acknowledge');
        Route::post('/check-ins/{checkIn}/comment', 'comment')->middleware('throttle:20,1')->name('comment');
    });
    // Leaving (brief §15): resignations, exit interviews, certificates of service. HR
    // administrators; the person's side under My work.
    Route::prefix('leaving')->name('leaving.')->controller(LeavingController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/resignations/{resignation}/acknowledge', 'acknowledge')->name('acknowledge');
        Route::post('/resignations/{resignation}/withdrawal/{decision}', 'withdrawal')->whereIn('decision', ['agree', 'decline'])->name('withdrawal');
        Route::post('/employees/{employee}/exit-interview', 'offerInterview')->name('offer-interview');
        Route::get('/exit-interviews/{interview}', 'interview')->name('interview');
        Route::post('/exit-interviews/{interview}', 'recordInterview')->name('interview.record');
        Route::get('/employees/{employee}/certificate', 'certificateForm')->name('certificate.create');
        Route::post('/employees/{employee}/certificate', 'issueCertificate')->name('certificate.store');
        Route::get('/certificates/{certificate}', 'certificate')->name('certificate');
    });
    Route::prefix('my-work')->name('my-work.')->controller(MyLeavingController::class)->group(function () {
        Route::post('/resignation', 'resign')->middleware('throttle:6,1')->name('resign');
        Route::post('/resignation/{resignation}/withdraw', 'withdraw')->middleware('throttle:3,1')->name('resignation.withdraw');
        Route::get('/exit-interview/{interview}', 'interview')->name('exit-interview');
        Route::post('/exit-interview/{interview}', 'answer')->middleware('throttle:5,1')->name('exit-interview.answer');
        Route::post('/exit-interview/{interview}/decline', 'decline')->name('exit-interview.decline');
        Route::get('/certificates/{certificate}', 'certificate')->name('certificate');
    });
    Route::prefix('my-work/cases')->name('my-work.cases.')->controller(MyCaseController::class)->group(function () {
        Route::post('/', 'lodge')->middleware('throttle:5,1')->name('lodge');
        Route::get('/{case}', 'show')->name('show');
        Route::post('/{case}/reply', 'reply')->middleware('throttle:20,1')->name('reply');
        Route::post('/{case}/appeal', 'appeal')->middleware('throttle:5,1')->name('appeal');
        Route::post('/entries/{entry}/received', 'received')->name('received');
    });

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

    // Time and competency (R-32 Phase 6a): schedule, attendance, timesheets, certifications.
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('/schedule/swaps', [SwapController::class, 'index'])->name('schedule.swaps');
    Route::put('/schedule/timezone', [ScheduleController::class, 'timezone'])->name('schedule.timezone');
    Route::post('/schedule/swaps/{swap}/{decision}', [SwapController::class, 'decide'])->whereIn('decision', ['approve', 'decline'])->name('schedule.swaps.decide');
    Route::post('/my-work/shifts/{shift}/offer', [MySwapController::class, 'offer'])->middleware('throttle:20,1')->name('my-work.swaps.offer');
    Route::post('/my-work/swaps/{swap}/accept', [MySwapController::class, 'accept'])->middleware('throttle:20,1')->name('my-work.swaps.accept');
    Route::post('/my-work/swaps/{swap}/decline', [MySwapController::class, 'decline'])->name('my-work.swaps.decline');
    Route::post('/my-work/swaps/{swap}/cancel', [MySwapController::class, 'cancel'])->name('my-work.swaps.cancel');
    Route::post('/schedule', [ScheduleController::class, 'store'])->name('schedule.store');
    Route::post('/schedule/{shift}/assign', [ScheduleController::class, 'assign'])->name('schedule.assign');
    Route::delete('/schedule/{shift}/assign/{employee}', [ScheduleController::class, 'unassign'])->whereNumber('employee')->name('schedule.unassign');
    Route::delete('/schedule/{shift}', [ScheduleController::class, 'destroy'])->name('schedule.destroy');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::put('/attendance/{record}', [AttendanceController::class, 'update'])->name('attendance.update');

    Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
    Route::post('/timesheets/{timesheet}/{decision}', [TimesheetController::class, 'decide'])->whereIn('decision', ['approve', 'reject'])->name('timesheets.decide');
    Route::put('/timesheets/settings', [TimesheetController::class, 'settings'])->name('timesheets.settings');

    Route::get('/certifications', [CertificationController::class, 'index'])->name('certifications.index');
    Route::post('/certifications', [CertificationController::class, 'store'])->name('certifications.store');
    Route::post('/certifications/{certification}/verify', [CertificationController::class, 'verify'])->name('certifications.verify');
    Route::delete('/certifications/{certification}', [CertificationController::class, 'destroy'])->name('certifications.destroy');
    Route::post('/positions/{position}/requirements', [CertificationController::class, 'addRequirement'])->name('requirements.store');
    Route::delete('/requirements/{requirement}', [CertificationController::class, 'removeRequirement'])->name('requirements.destroy');

    // Contractors and development (R-32 Phase 6b): engagements, reviews, goals, training.
    Route::get('/contractors', [ContractorController::class, 'index'])->name('contractors.index');
    Route::post('/contractors', [ContractorController::class, 'store'])->name('contractors.store');
    Route::get('/contractors/{engagement}', [ContractorController::class, 'show'])->name('contractors.show');
    Route::post('/contractors/{engagement}/renew', [ContractorController::class, 'renew'])->name('contractors.renew');
    Route::post('/contractors/{engagement}/end', [ContractorController::class, 'end'])->name('contractors.end');
    Route::post('/contractors/{engagement}/milestones', [ContractorController::class, 'addMilestone'])->name('contractors.milestones.store');
    Route::post('/contractors/{engagement}/invoices', [ContractorController::class, 'addInvoice'])->name('contractors.invoices.store');
    Route::post('/milestones/{milestone}/{decision}', [ContractorController::class, 'decideMilestone'])->whereIn('decision', ['approve', 'reject'])->name('milestones.decide');
    Route::post('/invoices/{invoice}/status', [ContractorController::class, 'moveInvoice'])->name('invoices.move');
    Route::get('/invoices/{invoice}/file', [ContractorController::class, 'invoiceFile'])->middleware('throttle:60,1')->name('invoices.file');
    Route::post('/my-work/engagements/{engagement}/invoices', [MyContractorController::class, 'submit'])->middleware('throttle:10,1')->name('my-work.invoices.store');
    Route::get('/my-work/invoices/{invoice}/file', [MyContractorController::class, 'file'])->middleware('throttle:60,1')->name('my-work.invoices.file');
    Route::put('/my-work/contractor-details', [MyContractorController::class, 'details'])->middleware('throttle:20,1')->name('my-work.contractor-details');

    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews/cycles', [ReviewController::class, 'storeCycle'])->name('reviews.cycles.store');
    Route::get('/reviews/cycles/{cycle}', [ReviewController::class, 'cycle'])->name('reviews.cycle');
    Route::post('/reviews/cycles/{cycle}/people', [ReviewController::class, 'addPeople'])->name('reviews.cycle.people');
    Route::post('/reviews/cycles/{cycle}/close', [ReviewController::class, 'closeCycle'])->name('reviews.cycle.close');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
    Route::post('/reviews/{review}/reflection', [ReviewController::class, 'reflect'])->middleware('throttle:20,1')->name('reviews.reflect');
    Route::post('/reviews/{review}/skip-reflection', [ReviewController::class, 'skip'])->name('reviews.skip');
    Route::post('/reviews/{review}/share', [ReviewController::class, 'share'])->middleware('throttle:20,1')->name('reviews.share');
    Route::post('/reviews/{review}/acknowledge', [ReviewController::class, 'acknowledge'])->middleware('throttle:20,1')->name('reviews.acknowledge');
    Route::put('/reviews/{review}/reviewer', [ReviewController::class, 'reassign'])->name('reviews.reassign');

    Route::post('/people/{employee}/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::put('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');

    // Intelligence (R-32 Phase 7): the business's own numbers and skills picture.
    Route::get('/insights', [InsightsController::class, 'index'])->name('insights.index');

    // Competencies and 360 feedback (brief §10): the framework and matrix for HR, each person's
    // place in it, and written feedback from colleagues.
    Route::get('/competencies', [CompetencyController::class, 'index'])->name('competencies.index');
    Route::post('/competencies', [CompetencyController::class, 'store'])->name('competencies.store');
    Route::put('/competencies/{competency}', [CompetencyController::class, 'update'])->name('competencies.update');
    Route::post('/competencies/{competency}/retire', [CompetencyController::class, 'retire'])->name('competencies.retire');
    Route::put('/competencies/matrix/{position}', [CompetencyController::class, 'matrix'])->name('competencies.matrix');
    Route::get('/employees/{employee}/competencies', [CompetencyController::class, 'person'])->name('employees.competencies');
    Route::post('/employees/{employee}/competencies/{competency}', [CompetencyController::class, 'agree'])->name('employees.competencies.agree');
    Route::get('/my-work/competencies', [MyCompetencyController::class, 'index'])->name('my-work.competencies');
    Route::post('/my-work/competencies/{competency}', [MyCompetencyController::class, 'assess'])->middleware('throttle:60,1')->name('my-work.competencies.assess');
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::post('/feedback/rounds', [FeedbackController::class, 'start'])->middleware('throttle:10,1')->name('feedback.rounds.store');
    Route::get('/feedback/rounds/{round}', [FeedbackController::class, 'round'])->name('feedback.rounds.show');
    Route::post('/feedback/rounds/{round}/share', [FeedbackController::class, 'share'])->name('feedback.rounds.share');
    Route::post('/feedback/rounds/{round}/close', [FeedbackController::class, 'close'])->name('feedback.rounds.close');
    Route::get('/feedback/requests/{response}', [FeedbackController::class, 'respond'])->name('feedback.respond');
    Route::post('/feedback/requests/{response}', [FeedbackController::class, 'answer'])->middleware('throttle:20,1')->name('feedback.answer');
    Route::post('/feedback/requests/{response}/decline', [FeedbackController::class, 'decline'])->name('feedback.decline');
    Route::get('/insights/export.csv', [InsightsController::class, 'export'])->middleware('throttle:20,1')->name('insights.export');

    Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
    Route::get('/training/report.csv', [TrainingController::class, 'report'])->middleware('throttle:20,1')->name('training.report');
    Route::post('/training/budgets', [TrainingController::class, 'storeBudget'])->name('training.budgets.store');
    Route::delete('/training/budgets/{budget}', [TrainingController::class, 'destroyBudget'])->name('training.budgets.destroy');
    Route::post('/training', [TrainingController::class, 'store'])->name('training.store');
    Route::post('/training/{training}/complete', [TrainingController::class, 'complete'])->name('training.complete');
    Route::post('/training/{training}/{decision}', [TrainingController::class, 'decide'])->whereIn('decision', ['approve', 'decline'])->name('training.decide');

    // Learning paths (brief §10): ordered steps per position or open to anyone; progress is steps
    // done, never a score.
    Route::get('/learning', [LearningController::class, 'index'])->name('learning.index');
    Route::post('/learning/paths', [LearningController::class, 'store'])->name('learning.paths.store');
    Route::get('/learning/paths/{path}', [LearningController::class, 'show'])->name('learning.paths.show');
    Route::put('/learning/paths/{path}', [LearningController::class, 'update'])->name('learning.paths.update');
    Route::post('/learning/paths/{path}/retire', [LearningController::class, 'retire'])->name('learning.paths.retire');
    Route::post('/learning/paths/{path}/steps', [LearningController::class, 'storeStep'])->name('learning.steps.store');
    Route::put('/learning/steps/{step}', [LearningController::class, 'updateStep'])->name('learning.steps.update');
    Route::post('/learning/steps/{step}/move/{direction}', [LearningController::class, 'moveStep'])->whereIn('direction', ['up', 'down'])->name('learning.steps.move');
    Route::post('/learning/steps/{step}/retire', [LearningController::class, 'retireStep'])->name('learning.steps.retire');
    Route::post('/learning/paths/{path}/enrol', [LearningController::class, 'enrol'])->name('learning.enrol');
    Route::post('/learning/paths/{path}/enrol-position', [LearningController::class, 'enrolPosition'])->name('learning.enrol-position');
    Route::get('/learning/people/{enrolment}', [LearningController::class, 'enrolment'])->name('learning.enrolments.show');
    Route::post('/learning/people/{enrolment}/steps/{step}/done', [LearningController::class, 'done'])->name('learning.steps.done');
    Route::post('/learning/people/{enrolment}/steps/{step}/undo', [LearningController::class, 'undo'])->name('learning.steps.undo');
    Route::post('/learning/people/{enrolment}/steps/{step}/book', [LearningController::class, 'book'])->name('learning.steps.book');
    Route::post('/learning/people/{enrolment}/drop', [LearningController::class, 'drop'])->name('learning.enrolments.drop');
    Route::prefix('my-work/learning')->name('my-work.learning.')->controller(MyLearningController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/paths/{path}/join', 'join')->middleware('throttle:20,1')->name('join');
        Route::get('/{enrolment}', 'show')->name('show');
        Route::post('/{enrolment}/steps/{step}/done', 'done')->middleware('throttle:60,1')->name('done');
        Route::post('/{enrolment}/steps/{step}/undo', 'undo')->middleware('throttle:60,1')->name('undo');
        Route::post('/{enrolment}/steps/{step}/ask', 'ask')->middleware('throttle:10,1')->name('ask');
    });

    // ...and the employee's own self-service in the current business. No employee id in
    // any route: everything acts on the record linked to the signed-in account.
    Route::get('/my-work/my-data', [PersonalDataController::class, 'mine'])->middleware('throttle:5,1')->name('my-work.data');
    Route::get('/me/my-data', [PersonalDataController::class, 'career'])->middleware('throttle:5,1')->name('me.data');
    Route::prefix('my-work')->name('my-work.')->controller(MyWorkController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/details', 'updateDetails')->middleware('throttle:30,1')->name('details');
        Route::post('/tasks/{task}', 'completeTask')->name('tasks.complete');
        Route::post('/requests', 'storeRequest')->middleware('throttle:10,1')->name('requests.store');
        Route::get('/requests/{hrRequest}', 'showRequest')->name('requests.show');
        Route::post('/clock/{direction}', 'clock')->whereIn('direction', ['in', 'out'])->middleware('throttle:20,1')->name('clock');
        Route::post('/timesheet', 'submitWeek')->middleware('throttle:20,1')->name('timesheet.submit');
        Route::post('/training', 'requestTraining')->middleware('throttle:10,1')->name('training.store');
        Route::post('/goals', 'storeGoal')->middleware('throttle:30,1')->name('goals.store');
        Route::post('/milestones/{milestone}/deliver', 'deliverMilestone')->middleware('throttle:20,1')->name('milestones.deliver');
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
        Route::post('/roles/{vacancy}/bulk', [ApplicationController::class, 'bulk'])->middleware('throttle:30,1')->name('bulk');
        Route::get('/candidates', [CandidateController::class, 'index'])->name('candidates');
        Route::get('/steps', [HiringSetupController::class, 'steps'])->name('steps');
        Route::post('/steps', [HiringSetupController::class, 'storeStep'])->name('steps.store');
        Route::post('/steps/{step}/move/{direction}', [HiringSetupController::class, 'moveStep'])->whereIn('direction', ['up', 'down'])->name('steps.move');
        Route::post('/steps/{step}/retire', [HiringSetupController::class, 'retireStep'])->name('steps.retire');
        Route::post('/roles/{vacancy}/costs', [HiringSetupController::class, 'storeCost'])->name('costs.store');
        Route::delete('/costs/{cost}', [HiringSetupController::class, 'destroyCost'])->name('costs.destroy');
        Route::post('/applications/{application}/step', [ApplicationController::class, 'step'])->name('applications.step');
        Route::post('/roles/{vacancy}/{to}', [VacancyController::class, 'transition'])
            ->whereIn('to', ['publish', 'pause', 'close', 'reopen'])->name('transition');
        Route::post('/roles/{vacancy}/internal/{employee}', [InternalTalentController::class, 'invite'])
            ->middleware('throttle:30,1')->name('internal.invite');

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
// Readiness for uptime monitors (R-32 Phase 8): checks, not data.
Route::get('/ready', ReadyController::class)->middleware('throttle:60,1')->name('ready');
Route::post('/csp-report', CspReportController::class)->middleware('throttle:60,1')->name('csp.report');
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
