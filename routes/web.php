<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRequestController;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Route;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $team = auth()->user()->currentTeam;

        // Employee/LeaveRequest queries below rely on HasTeamScope's global
        // scope for team isolation now, not an explicit where('team_id', ...)
        // — see app/Models/Concerns/HasTeamScope.php.
        return view('dashboard', [
            'headcount' => Employee::where('status', 'active')->count(),
            'onLeaveCount' => Employee::where('status', 'on_leave')->count(),
            'pendingLeaveRequests' => LeaveRequest::where('status', 'pending')->count(),
            'openPositions' => $team->positions()->count(),
            'recentLeaveRequests' => LeaveRequest::where('status', 'pending')
                ->with('employee')
                ->latest()
                ->take(5)
                ->get(),
        ]);
    })->name('dashboard');

    Route::resource('employees', EmployeeController::class);

    Route::resource('leave-requests', LeaveRequestController::class)
        ->except(['edit', 'update']);
    Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
        ->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/deny', [LeaveRequestController::class, 'deny'])
        ->name('leave-requests.deny');
});
