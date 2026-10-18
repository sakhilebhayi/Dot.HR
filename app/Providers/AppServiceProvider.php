<?php

namespace App\Providers;

use App\Listeners\LinkEmployeesOnLogin;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Observers\EmployeeObserver;
use App\Observers\LeaveRequestObserver;
use App\Observers\LeaveTypeObserver;
use App\Observers\PositionObserver;
use App\Services\Hr\LeaveCalendar;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Not a singleton: it reads config/hr.php each time, so weekend and holiday changes apply at once.
        $this->app->bind(LeaveCalendar::class, fn () => LeaveCalendar::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LeaveRequest::observe(LeaveRequestObserver::class);
        Employee::observe(EmployeeObserver::class);
        Position::observe(PositionObserver::class);
        LeaveType::observe(LeaveTypeObserver::class);

        Event::listen(Login::class, LinkEmployeesOnLogin::class);
    }
}
