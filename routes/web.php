<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\LockoutController;
use App\Http\Controllers\Admin\RegistrationFeeRuleController;
use App\Http\Controllers\AgentDirectoryController;
use App\Http\Controllers\AgentInvitationController;
use App\Http\Controllers\AgentPhotoController;
use App\Http\Controllers\AgentProfileController;
use App\Http\Controllers\AgentRegistrationController;
use App\Http\Controllers\Auth\AgentActivationController;
use App\Http\Controllers\Auth\AssistedRecoveryHandoffController;
use App\Http\Controllers\Auth\CustomerActivationController;
use App\Http\Controllers\Auth\DeviceEvictionController;
use App\Http\Controllers\Auth\FreshAuthenticationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorEnrolmentController;
use App\Http\Controllers\Auth\TwoFactorManagementController;
use App\Http\Controllers\CustomerDirectoryController;
use App\Http\Controllers\CustomerInvitationController;
use App\Http\Controllers\CustomerPhotoController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerRegistrationController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\ProfileIdentityController;
use App\Http\Controllers\ProfileManagementController;
use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

// Agent Activation via Hashed Challenge (CAM-T05)
Route::get('invitations/agent/{token}', [AgentActivationController::class, 'show'])->name('invitations.agent.show');
Route::post('invitations/agent/{token}', [AgentActivationController::class, 'activate'])->name('invitations.agent.activate');

// Customer Activation via Hashed Challenge (CAM-T06)
Route::get('invitations/customer/{token}', [CustomerActivationController::class, 'show'])->name('invitations.customer.show');
Route::post('invitations/customer/{token}', [CustomerActivationController::class, 'activate'])->name('invitations.customer.activate');

Route::middleware(['auth'])->group(function () {
    // Customer Registration and Attempts (CAM-T06)
    Route::get('customers/create', [CustomerRegistrationController::class, 'create'])->name('customers.create');
    Route::get('customers/fee-preview', [CustomerRegistrationController::class, 'feePreview'])->name('customers.fee-preview');
    Route::post('customers', [CustomerRegistrationController::class, 'store'])->name('customers.store');
    Route::get('customers/attempts/{reference}', [CustomerRegistrationController::class, 'showAttempt'])->name('customers.attempts.show');

    // Customer & Agent Directories and Profiles (CAM-T04)
    Route::get('customers', [CustomerDirectoryController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}/edit', [ProfileManagementController::class, 'editCustomer'])->name('customers.edit');
    Route::patch('customers/{customer}', [ProfileManagementController::class, 'updateCustomer'])->name('customers.update');
    Route::post('customers/{customer}/name', [ProfileIdentityController::class, 'changeOwnName'])->middleware('fresh')->name('customers.name.update');
    Route::post('customers/{customer}/name-corrections', [ProfileIdentityController::class, 'storeNameCorrection'])->name('customers.name-corrections.store');
    Route::post('customers/{customer}/phone', [ProfileIdentityController::class, 'changeOwnCustomerPhone'])->middleware('fresh')->name('customers.phone.self');
    Route::post('customers/{customer}/phone-correction', [ProfileIdentityController::class, 'correctCustomerPhone'])->name('customers.phone-corrections.store');
    Route::get('customers/{customer}/name-corrections/{correction}', [ProfileIdentityController::class, 'showNameCorrection'])->name('customers.name-corrections.show');
    Route::post('customers/{customer}/name-corrections/{correction}/accept', [ProfileIdentityController::class, 'acceptNameCorrection'])->middleware('fresh')->name('customers.name-corrections.accept');
    Route::post('customers/{customer}/name-corrections/{correction}/reject', [ProfileIdentityController::class, 'rejectNameCorrection'])->middleware('fresh')->name('customers.name-corrections.reject');
    Route::post('customers/{customer}/name-corrections/{correction}/cancel', [ProfileIdentityController::class, 'cancelNameCorrection'])->name('customers.name-corrections.cancel');
    Route::get('customers/{customer}', [CustomerProfileController::class, 'show'])->name('customers.show');
    Route::get('customers/{customer}/photo', [CustomerPhotoController::class, 'show'])->name('customers.photo');

    // Customer Invitation Management (CAM-T06)
    Route::post('customers/{customer}/invitation/resend', [CustomerInvitationController::class, 'resend'])->name('customers.invitations.resend');
    Route::post('customers/{customer}/invitation/correct-email', [CustomerInvitationController::class, 'correctEmail'])->name('customers.invitations.correct-email');
    Route::post('customers/{customer}/invitation/cancel', [CustomerInvitationController::class, 'cancel'])->name('customers.invitations.cancel');

    // Agent Registration and Attempts (CAM-T05)
    Route::get('agents/create', [AgentRegistrationController::class, 'create'])->name('agents.create');
    Route::post('agents', [AgentRegistrationController::class, 'store'])->name('agents.store');
    Route::get('agents/attempts/{reference}', [AgentRegistrationController::class, 'showAttempt'])->name('agents.attempts.show');

    Route::get('agents', [AgentDirectoryController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}/edit', [ProfileManagementController::class, 'editAgent'])->name('agents.edit');
    Route::patch('agents/{agent}', [ProfileManagementController::class, 'updateAgent'])->name('agents.update');
    Route::post('agents/{agent}/phone', [ProfileIdentityController::class, 'changeOwnAgentPhone'])->middleware('fresh')->name('agents.phone.self');
    Route::post('agents/{agent}/phone-correction', [ProfileIdentityController::class, 'correctAgentPhone'])->name('agents.phone-corrections.store');
    Route::get('agents/{agent}', [AgentProfileController::class, 'show'])->name('agents.show');
    Route::get('agents/{agent}/photo', [AgentPhotoController::class, 'show'])->name('agents.photo');

    // Agent Invitation Management (CAM-T05)
    Route::post('agents/{agent}/invitation/resend', [AgentInvitationController::class, 'resend'])->name('agents.invitations.resend');
    Route::post('agents/{agent}/invitation/correct-email', [AgentInvitationController::class, 'correctEmail'])->name('agents.invitations.correct-email');
    Route::post('agents/{agent}/invitation/cancel', [AgentInvitationController::class, 'cancel'])->name('agents.invitations.cancel');

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

        // Registration Fee Rules (CAM-T06)
        Route::get('fees/registration', [RegistrationFeeRuleController::class, 'index'])->name('fees.registration.index');
        Route::post('fees/registration', [RegistrationFeeRuleController::class, 'store'])
            ->middleware('fresh')
            ->name('fees.registration.store');
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

Route::get('email-change/{pendingEmailChange}', [EmailChangeController::class, 'showConfirmation'])->name('email-change.confirm.show');
Route::post('email-change/{pendingEmailChange}', [EmailChangeController::class, 'confirm'])->name('email-change.confirm');

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
