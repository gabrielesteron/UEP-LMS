<?php

namespace App\Providers;

use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\Access;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
        Paginator::useBootstrapFive();
        Gate::define('manage-class', fn (User $user, TeacherAssignment $classroom) => in_array($user->role, ['admin', 'teacher']) && Access::classes($user)->whereKey($classroom->id)->exists());
    }
}
