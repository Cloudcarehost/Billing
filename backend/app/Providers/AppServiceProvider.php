<?php

namespace App\Providers;

use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Policies\HotelPolicy;
use App\Policies\OutletPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::policy(Hotel::class, HotelPolicy::class);
        Gate::policy(Outlet::class, OutletPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173'))[0];

            return rtrim($frontend, '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->id ?: $request->ip(),
        ));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip(),
        ));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip(),
        ));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by(
            (string) $request->user()?->id.'|'.$request->ip(),
        ));
        RateLimiter::for('onboarding', fn (Request $request) => Limit::perHour(3)->by($request->ip()));
        RateLimiter::for('public-table', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perHour(500)->by($request->ip()),
        ]);
        RateLimiter::for('public-table-call', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perHour(40)->by($request->ip()),
        ]);
    }
}
