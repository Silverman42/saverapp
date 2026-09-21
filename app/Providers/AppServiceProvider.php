<?php

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\PasswordPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->registerAuthorizationGates();
    }

    /**
     * Register closed catalogue AdminPermission Gate abilities.
     */
    protected function registerAuthorizationGates(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Gate::define($permission->value, function (User $user) use ($permission): bool {
                return app(AuthorizationService::class)->allows($user, $permission);
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => PasswordPolicy::defaultRule());
    }
}
