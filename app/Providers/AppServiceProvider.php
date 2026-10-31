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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Generous for a person clicking through HR work; a script hammering the app is cut off.
        RateLimiter::for('hr', fn (Request $request) => Limit::perMinute(240)->by((string) ($request->user()->id ?? $request->ip())));

        // Outside production a relation read in a loop without eager loading is an error, so
        // an N+1 query is caught by the test suite instead of by a slow page.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
