<?php

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\UnavailableExternalOutcomeLookup;
use App\Support\ExternalOutcomeLookup;
use App\Support\PasswordPolicy;
use App\Support\PlatformJobMiddleware;
use App\Support\PlatformWorker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
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
        $this->app->bind(ExternalOutcomeLookup::class, UnavailableExternalOutcomeLookup::class);
        $this->app->extend('queue.worker', function (Worker $worker, Application $app): PlatformWorker {
            return new PlatformWorker($app['queue'], $app['events'], $app[ExceptionHandler::class],
                fn (): bool => $app->isDownForMaintenance(), function () use ($app): void {
                    $app['log']->flushSharedContext();
                    $app['log']->withoutContext();
                    foreach ($app['db']->getConnections() as $connection) {
                        $connection->resetTotalQueryDuration();
                        $connection->allowQueryDurationHandlersToRunAgain();
                    }
                    $app->forgetScopedInstances();
                    Facade::clearResolvedInstances();
                    memory_reset_peak_usage();
                });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Bus::pipeThrough([PlatformJobMiddleware::class]);
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
