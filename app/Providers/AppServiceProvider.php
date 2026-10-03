<?php

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Notifications\CollectionReceiptMailNotification;
use App\Notifications\FeeSavingsApplicationMailNotification;
use App\Notifications\FinancialCashMailNotification;
use App\Notifications\ManualChargeMailNotification;
use App\Notifications\ThriftPlanNotification;
use App\Services\AuthorizationService;
use App\Services\UnavailableExternalOutcomeLookup;
use App\Support\ExternalOutcomeLookup;
use App\Support\PasswordPolicy;
use App\Support\PlatformJobMiddleware;
use App\Support\PlatformWorker;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        Event::listen(Login::class, static function (Login $event): void {
            $request = app('request');
            if ($event->user instanceof User && $request->hasSession()) {
                $request->session()->put('auth.lifecycle_access_version', (int) $event->user->lifecycle_access_version);
            }
        });
        Event::listen(NotificationSent::class, static function (NotificationSent $event): void {
            if (($event->notification instanceof CollectionReceiptMailNotification || $event->notification instanceof FeeSavingsApplicationMailNotification || $event->notification instanceof ManualChargeMailNotification || $event->notification instanceof FinancialCashMailNotification || $event->notification instanceof ThriftPlanNotification)
                && $event->channel === 'mail') {
                $event->notification->deliveryEvidence->accepted = $event->response instanceof SentMessage;
            }
        });
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
