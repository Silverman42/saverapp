<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\LockoutController;
use App\Http\Controllers\AgentDirectoryController;
use App\Http\Controllers\AgentPhotoController;
use App\Http\Controllers\AgentProfileController;
use App\Http\Controllers\Auth\AssistedRecoveryHandoffController;
use App\Http\Controllers\Auth\DeviceEvictionController;
use App\Http\Controllers\Auth\FreshAuthenticationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorEnrolmentController;
use App\Http\Controllers\Auth\TwoFactorManagementController;
use App\Http\Controllers\CustomerDirectoryController;
use App\Http\Controllers\CustomerPhotoController;
use App\Http\Controllers\CustomerProfileController;
use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    // Customer & Agent Directories and Profiles (CAM-T04)
    Route::get('customers', [CustomerDirectoryController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}', [CustomerProfileController::class, 'show'])->name('customers.show');
    Route::get('customers/{customer}/photo', [CustomerPhotoController::class, 'show'])->name('customers.photo');

    Route::get('agents', [AgentDirectoryController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}', [AgentProfileController::class, 'show'])->name('agents.show');
    Route::get('agents/{agent}/photo', [AgentPhotoController::class, 'show'])->name('agents.photo');
    Route::get('dashboard', function (Request $request) {
        return redirect()->route(RoleDestinationResolver::resolveRouteName($request->user()));
    })->name('dashboard');

    Route::get('customer/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:customer')
        ->name('customer.dashboard');

    Route::get('agent/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:agent')
        ->name('agent.dashboard');

    Route::get('admin/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:admin')
        ->name('admin.dashboard');

    // Admin Lockout & Abuse Visibility (AUTH-061, AUTH-063)
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('lockouts', [LockoutController::class, 'index'])->name('lockouts.index');
        Route::post('lockouts/{user}/unlock', [LockoutController::class, 'unlock'])->name('lockouts.unlock');

        Route::get('access', [AdminAccessController::class, 'index'])->name('access.index');
        Route::get('access/{admin}', [AdminAccessController::class, 'show'])->name('access.show');
        Route::put('access/{admin}/permissions', [AdminAccessController::class, 'update'])
            ->middleware('fresh')
            ->name('access.permissions.update');
    });

    // Step-up Fresh Authentication (AUTHZ-T04)
    Route::get('user/fresh-authentication', [FreshAuthenticationController::class, 'show'])
        ->name('fresh-authentication');
    Route::post('user/fresh-authentication', [FreshAuthenticationController::class, 'store'])
        ->name('fresh-authentication.store');

    // Mandatory MFA Enrolment & Acknowledgement
    Route::get('two-factor-enrolment', [TwoFactorEnrolmentController::class, 'show'])
        ->name('two-factor.enrolment');
    Route::post('two-factor-enrolment/confirm', [TwoFactorEnrolmentController::class, 'confirm'])
        ->name('two-factor.enrolment.confirm');
    Route::post('two-factor-enrolment/acknowledge', [TwoFactorEnrolmentController::class, 'acknowledge'])
        ->name('two-factor.enrolment.acknowledge');

    // Two-factor Management & Replacement
    Route::get('user/two-factor-qr-code', [TwoFactorManagementController::class, 'qrCode'])
        ->name('two-factor.qr-code');
    Route::get('user/two-factor-secret-key', [TwoFactorManagementController::class, 'secretKey'])
        ->name('two-factor.secret-key');
    Route::get('user/two-factor-recovery-codes', [TwoFactorManagementController::class, 'showRecoveryCodes'])
        ->name('two-factor.recovery-codes');
    Route::post('user/two-factor-recovery-codes', [TwoFactorManagementController::class, 'regenerateRecoveryCodes'])
        ->name('two-factor.regenerate-recovery-codes');
    Route::delete('user/two-factor-authentication', [TwoFactorManagementController::class, 'disable'])
        ->name('two-factor.disable');
    Route::post('user/two-factor-authentication/replace', [TwoFactorManagementController::class, 'replace'])
        ->name('two-factor.replace');
    Route::post('user/two-factor-authentication/replace/confirm', [TwoFactorManagementController::class, 'confirmReplacement'])
        ->name('two-factor.replace.confirm');
    Route::delete('user/two-factor-authentication/replace/cancel', [TwoFactorManagementController::class, 'cancelReplacement'])
        ->name('two-factor.replace.cancel');

    // Active Session Management
    Route::get('sessions', [SessionController::class, 'index'])
        ->name('sessions.index');
    Route::delete('sessions/{id}', [SessionController::class, 'destroy'])
        ->name('sessions.destroy');
    Route::post('sessions/revoke-others', [SessionController::class, 'destroyOthers'])
        ->name('sessions.destroy-others');
    Route::post('sessions/revoke-all', [SessionController::class, 'destroyAll'])
        ->name('sessions.destroy-all');
});

// Device Eviction (pending authentication)
Route::get('device-eviction', [DeviceEvictionController::class, 'show'])
    ->name('device-eviction');
Route::post('device-eviction/confirm', [DeviceEvictionController::class, 'confirm'])
    ->name('device-eviction.confirm');
Route::post('device-eviction/cancel', [DeviceEvictionController::class, 'cancel'])
    ->name('device-eviction.cancel');

Route::get('assisted-recovery', AssistedRecoveryHandoffController::class)
    ->name('auth.assisted-recovery');

require __DIR__.'/settings.php';
