<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Observers\LeaveRequestObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LeaveRequest::observe(LeaveRequestObserver::class);
    }
}
