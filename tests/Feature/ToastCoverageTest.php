<?php

use App\Support\Toast;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Non-GET actions that intentionally flash no server toast.
 *
 * @return array<string, string>
 */
function toastExemptActions(): array
{
    $preview = 'Read-only preview requested with POST; nothing is saved.';
    $client = 'JSON action; its page shows the toast after the response.';

    return [
        'App\Http\Controllers\BankPayoutCallbackController@__invoke' => 'Provider webhook with no user session.',
        'App\Http\Controllers\NotificationInboxController@pageRead' => 'Background read receipt.',
        'App\Http\Controllers\NotificationInboxController@update' => 'Background read receipt.',
        'App\Http\Controllers\Admin\FeeActionAttemptController@prepare' => 'First step of the waive or correct action, which flashes the toast.',
        'App\Http\Controllers\Admin\FeeActionAttemptController@cancel' => $client,
        'App\Http\Controllers\Admin\FeeSavingsApplicationController@store' => $client,
        'App\Http\Controllers\BusinessLogoController@store' => $client,
        'App\Http\Controllers\CollectionEvidenceController@publish' => $client,
        'App\Http\Controllers\CollectionEvidenceController@review' => $client,
        'App\Http\Controllers\CollectionEvidenceController@store' => $client,
        'App\Http\Controllers\CollectionSettlementController@store' => $client,
        'App\Http\Controllers\CustomerReassignmentController@store' => $client,
        'App\Http\Controllers\CustomerRecoveryController@store' => $client,
        'App\Http\Controllers\CustomerRecoveryController@update' => $client,
        'App\Http\Controllers\Admin\FeeSavingsApplicationController@preview' => $preview,
        'App\Http\Controllers\Admin\ManualChargeController@preview' => $preview,
        'App\Http\Controllers\Admin\RegistrationFeeRuleController@preview' => $preview,
        'App\Http\Controllers\Admin\RegistrationFeeRuleController@previewRetirement' => $preview,
        'App\Http\Controllers\CollectionController@preview' => $preview,
        'App\Http\Controllers\CustomerLifecycleController@preview' => $preview,
        'App\Http\Controllers\CustomerReassignmentController@preview' => $preview,
        'App\Http\Controllers\FinancialWorkflowController@replacementPreview' => $preview,
        'App\Http\Controllers\ReversalController@preview' => $preview,
        'App\Http\Controllers\WithdrawalController@preview' => $preview,
    ];
}

/**
 * Whether the action, or a private helper it calls on the same controller, flashes a toast.
 *
 * @param  array<string, true>  $visited
 */
function actionFlashesToast(ReflectionClass $controller, string $method, array &$visited = []): bool
{
    if (isset($visited[$method]) || ! $controller->hasMethod($method)) {
        return false;
    }
    $visited[$method] = true;
    $reflection = $controller->getMethod($method);
    $lines = file($reflection->getFileName());
    $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    if (str_contains($source, 'Toast::')) {
        return true;
    }
    preg_match_all('/\$this->(\w+)\(/', $source, $calls);

    return collect($calls[1])->contains(fn (string $called): bool => actionFlashesToast($controller, $called, $visited));
}

test('every application non-GET action flashes a toast unless it is explicitly exempt', function (): void {
    $missing = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => array_diff($route->methods(), ['GET', 'HEAD']) !== [] && ! in_array('GET', $route->methods(), true))
        ->map(fn (Route $route): string => ltrim($route->getActionName(), '\\'))
        ->filter(fn (string $action): bool => str_starts_with($action, 'App\\Http\\Controllers\\'))
        ->map(fn (string $action): string => str_contains($action, '@') ? $action : $action.'@__invoke')
        ->unique()
        ->reject(fn (string $action): bool => array_key_exists($action, toastExemptActions()))
        ->reject(function (string $action): bool {
            [$class, $method] = explode('@', $action);

            return actionFlashesToast(new ReflectionClass($class), $method);
        })
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

test('every exempt action still exists so the exemption list cannot hide a removed route', function (): void {
    $actions = collect(Router::getRoutes()->getRoutes())
        ->map(fn (Route $route): string => ltrim($route->getActionName(), '\\'))
        ->map(fn (string $action): string => str_contains($action, '@') ? $action : $action.'@__invoke');

    expect(array_diff(array_keys(toastExemptActions()), $actions->all()))->toBe([]);
});

test('the toast helper flashes a typed title and optional description', function (): void {
    Toast::success('Event created', 'naira has been created successfully.');
    expect(session('inertia.flash_data.toast'))->toBe(['type' => 'success', 'title' => 'Event created', 'description' => 'naira has been created successfully.']);

    Toast::info('Sign-in cancelled');
    expect(session('inertia.flash_data.toast'))->toBe(['type' => 'info', 'title' => 'Sign-in cancelled']);

    expect(fn () => Toast::show('celebrate', 'Unknown'))->toThrow(InvalidArgumentException::class);
});
