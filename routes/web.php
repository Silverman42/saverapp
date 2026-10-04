<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BusinessSettingsController;
use App\Http\Controllers\Admin\FeeActionAttemptController;
use App\Http\Controllers\Admin\FeeOverviewController;
use App\Http\Controllers\Admin\FeeRefundController;
use App\Http\Controllers\Admin\FeeSavingsApplicationController;
use App\Http\Controllers\Admin\FinancialPeriodController;
use App\Http\Controllers\Admin\LockoutController;
use App\Http\Controllers\Admin\ManualChargeController;
use App\Http\Controllers\Admin\RegistrationFeeRuleController;
use App\Http\Controllers\Admin\SecurityCaseController;
use App\Http\Controllers\AgentDirectoryController;
use App\Http\Controllers\AgentInvitationController;
use App\Http\Controllers\AgentLifecycleController;
use App\Http\Controllers\AgentPhotoController;
use App\Http\Controllers\AgentProfileController;
use App\Http\Controllers\AgentRegistrationController;
use App\Http\Controllers\AgentStatusController;
use App\Http\Controllers\Auth\AgentActivationController;
use App\Http\Controllers\Auth\AssistedRecoveryHandoffController;
use App\Http\Controllers\Auth\CustomerActivationController;
use App\Http\Controllers\Auth\DeviceEvictionController;
use App\Http\Controllers\Auth\FreshAuthenticationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorEnrolmentController;
use App\Http\Controllers\Auth\TwoFactorManagementController;
use App\Http\Controllers\BankPayoutCallbackController;
use App\Http\Controllers\BankPayoutController;
use App\Http\Controllers\BankPayoutDestinationController;
use App\Http\Controllers\CashDisbursementController;
use App\Http\Controllers\CashExecutionController;
use App\Http\Controllers\CashRecoveryController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CollectionEvidenceController;
use App\Http\Controllers\CollectionSettlementController;
use App\Http\Controllers\CustomerDirectoryController;
use App\Http\Controllers\CustomerInvitationController;
use App\Http\Controllers\CustomerLifecycleController;
use App\Http\Controllers\CustomerPhotoController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerReassignmentController;
use App\Http\Controllers\CustomerRecoveryController;
use App\Http\Controllers\CustomerRegistrationController;
use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\CustomerStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\FinancialArtifactController;
use App\Http\Controllers\FinancialWorkflowController;
use App\Http\Controllers\LedgerTransactionController;
use App\Http\Controllers\ManagementDeliveryController;
use App\Http\Controllers\NotificationInboxController;
use App\Http\Controllers\ProfileIdentityController;
use App\Http\Controllers\ProfileManagementController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReversalController;
use App\Http\Controllers\ThriftPlanController;
use App\Http\Controllers\WithdrawalController;
use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Agent Activation via Hashed Challenge (CAM-T05)
Route::get('invitations/agent/{token}', [AgentActivationController::class, 'show'])->name('invitations.agent.show');
Route::post('invitations/agent/{token}', [AgentActivationController::class, 'activate'])->name('invitations.agent.activate');

// Customer Activation via Hashed Challenge (CAM-T06)
Route::get('invitations/customer/{token}', [CustomerActivationController::class, 'show'])->name('invitations.customer.show');
Route::post('invitations/customer/{token}', [CustomerActivationController::class, 'activate'])->name('invitations.customer.activate');

Route::get('customer-recovery/{recovery}/activate', [CustomerRecoveryController::class, 'activation'])->whereUuid('recovery')->name('customer-recovery.activation');
Route::post('customer-recovery/{recovery}/activate', [CustomerRecoveryController::class, 'activate'])->whereUuid('recovery')->middleware('throttle:5,1')->name('customer-recovery.activate');

Route::middleware(['auth'])->group(function () {
    Route::get('collection-methods', [CollectionEvidenceController::class, 'methods'])->name('collection-methods.index');
    Route::get('collection-methods/manage', [CollectionEvidenceController::class, 'manage'])->name('collection-methods.manage');
    Route::get('collection-methods/publications/{reference}', [CollectionEvidenceController::class, 'publicationResult'])->whereUuid('reference')->name('collection-methods.publication-result');
    Route::post('collection-methods', [CollectionEvidenceController::class, 'publish'])->middleware('fresh')->name('collection-methods.store');
    Route::post('customers/{customer}/collection-evidence', [CollectionEvidenceController::class, 'store'])->middleware('throttle:20,1')->name('customers.collection-evidence.store');
    Route::get('collection-evidence', [CollectionEvidenceController::class, 'index'])->name('collection-evidence.index');
    Route::get('collection-evidence/{reference}/view', [CollectionEvidenceController::class, 'view'])->whereUuid('reference')->name('collection-evidence.view');
    Route::get('collection-evidence/{reference}', [CollectionEvidenceController::class, 'show'])->whereUuid('reference')->name('collection-evidence.show');
    Route::get('collection-evidence/{reference}/reviews/{operation}', [CollectionEvidenceController::class, 'reviewResult'])->whereUuid(['reference', 'operation'])->middleware('fresh')->name('collection-evidence.reviews.show');
    Route::post('collection-evidence/{reference}/review', [CollectionEvidenceController::class, 'review'])->whereUuid('reference')->middleware('fresh')->name('collection-evidence.review');
    Route::get('collection-evidence/{reference}/files/{file}/link', [CollectionEvidenceController::class, 'link'])->whereUuid('reference')->whereNumber('file')->name('collection-evidence.files.link');
    Route::get('collection-evidence/{reference}/files/{file}/download', [CollectionEvidenceController::class, 'download'])->whereUuid('reference')->whereNumber('file')->middleware('signed')->name('collection-evidence.files.download');
    Route::post('collection-batches/{batch}/settlements', [CollectionSettlementController::class, 'store'])->middleware(['fresh', 'throttle:20,1'])->name('collection-batches.settlements.store');
    Route::get('collection-batches/{batch}/settlements/{reference}', [CollectionSettlementController::class, 'show'])->whereUuid('reference')->name('collection-batches.settlements.show');
    Route::get('collection-settlements/{reference}/files/{file}/link', [CollectionSettlementController::class, 'link'])->whereUuid('reference')->whereNumber('file')->name('collection-settlements.files.link');
    Route::get('collection-settlements/{reference}/files/{file}/download', [CollectionSettlementController::class, 'download'])->whereUuid('reference')->whereNumber('file')->middleware('signed')->name('collection-settlements.files.download');
    Route::get('customer-recovery', [CustomerRecoveryController::class, 'index'])->name('customer-recovery.index');
    Route::get('customers/{customer}/reassignment', [CustomerReassignmentController::class, 'edit'])->name('customers.reassignment.edit');
    Route::post('customers/{customer}/reassignment/preview', [CustomerReassignmentController::class, 'preview'])->name('customers.reassignment.preview');
    Route::post('customers/{customer}/reassignment', [CustomerReassignmentController::class, 'store'])->name('customers.reassignment.store');
    Route::get('customers/{customer}/reassignment/operations/{attempt_reference}', [CustomerReassignmentController::class, 'operation'])->whereUuid('attempt_reference')->name('customers.reassignment.operation');
    Route::get('customers/{customer}/recovery/review', [CustomerRecoveryController::class, 'show'])->middleware('fresh')->name('customers.recovery.review');
    Route::get('customers/{customer}/recovery', [CustomerRecoveryController::class, 'show'])->name('customers.recovery.show');
    Route::post('customers/{customer}/recovery', [CustomerRecoveryController::class, 'store'])->middleware('throttle:10,1')->name('customers.recovery.store');
    Route::post('customers/{customer}/recovery/{recovery}/{action}', [CustomerRecoveryController::class, 'update'])->whereUuid('recovery')->whereIn('action', ['verify', 'approve', 'reject', 'cancel', 'reissue'])->middleware('throttle:10,1')->name('customers.recovery.update');
    Route::get('customers/{customer}/recovery/operations/{attempt_reference}', [CustomerRecoveryController::class, 'operation'])->whereUuid('attempt_reference')->name('customers.recovery.operation');
    Route::get('customers/{customer}/delivery', [ManagementDeliveryController::class, 'customer'])->middleware('throttle:60,1')->name('customers.delivery.index');
    Route::get('agents/{agent}/delivery', [ManagementDeliveryController::class, 'agent'])->middleware('throttle:60,1')->name('agents.delivery.index');
    Route::get('notifications', [NotificationInboxController::class, 'index'])->name('notifications.index');
    Route::get('notifications/sync', [NotificationInboxController::class, 'sync'])->name('notifications.sync');
    Route::post('notifications/page-read', [NotificationInboxController::class, 'pageRead'])->name('notifications.page-read');
    Route::get('notifications/{notification}', [NotificationInboxController::class, 'show'])->whereUuid('notification')->name('notifications.show');
    Route::patch('notifications/{notification}/read', [NotificationInboxController::class, 'update'])->whereUuid('notification')->name('notifications.read');
    Route::get('notifications/{notification}/open', [NotificationInboxController::class, 'open'])->whereUuid('notification')->name('notifications.open');

    // Customer Registration and Attempts (CAM-T06)
    Route::get('customers/create', [CustomerRegistrationController::class, 'create'])->name('customers.create');
    Route::get('customers/fee-preview', [CustomerRegistrationController::class, 'feePreview'])->name('customers.fee-preview');
    Route::post('customers', [CustomerRegistrationController::class, 'store'])->name('customers.store');
    Route::get('customers/attempts/{reference}', [CustomerRegistrationController::class, 'showAttempt'])->name('customers.attempts.show');

    // Customer & Agent Directories and Profiles (CAM-T04)
    Route::get('customers', [CustomerDirectoryController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}/status', [CustomerStatusController::class, 'edit'])->name('customers.status.edit');
    Route::patch('customers/{customer}/status', [CustomerStatusController::class, 'update'])->name('customers.status.update');
    Route::post('customers/{customer}/lifecycle/preview', [CustomerLifecycleController::class, 'preview'])->name('customers.lifecycle.preview');
    Route::post('customers/{customer}/lifecycle/archive', [CustomerLifecycleController::class, 'archive'])->name('customers.lifecycle.archive');
    Route::post('customers/{customer}/lifecycle/restore', [CustomerLifecycleController::class, 'restore'])->name('customers.lifecycle.restore');
    Route::get('customers/{customer}/lifecycle/operations/{attempt_reference}', [CustomerLifecycleController::class, 'operation'])->whereUuid('attempt_reference')->name('customers.lifecycle.operation');
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
    Route::get('customers/{customer}/ledger-balance', [LedgerTransactionController::class, 'balance'])->middleware('throttle:120,1,ledger-read')->name('customers.ledger-balance');
    Route::get('customers/{customer}/statements/preview', [CustomerStatementController::class, 'preview'])->middleware('throttle:60,1,statement-preview')->name('customers.statements.preview');
    Route::get('customers/{customer}/access', [CustomerProfileController::class, 'access'])->name('customers.access');
    Route::get('customers/{customer}', [CustomerProfileController::class, 'show'])->name('customers.show');
    Route::get('customers/{customer}/photo', [CustomerPhotoController::class, 'show'])->name('customers.photo');

    // Thrift plan core (Module 06)
    Route::get('plans', [ThriftPlanController::class, 'index'])->name('plans.index');
    Route::get('customers/{customer}/plans/create', [ThriftPlanController::class, 'create'])->name('customers.plans.create');
    Route::post('customers/{customer}/plans', [ThriftPlanController::class, 'store'])->name('customers.plans.store');
    Route::get('plans/attempts/{reference}', [ThriftPlanController::class, 'showAttempt'])->name('plans.attempts.show');
    Route::get('plans/{plan}/edit', [ThriftPlanController::class, 'edit'])->name('plans.edit');
    Route::get('plans/{plan}/card', [CollectionController::class, 'card'])->middleware('collections.enabled')->name('plans.card');
    Route::post('plans/{plan}/card/{slot}/annotations', [CollectionController::class, 'annotate'])->middleware('collections.enabled')->name('plans.card.annotations.store');
    Route::patch('plans/{plan}', [ThriftPlanController::class, 'update'])->name('plans.update');
    Route::post('plans/{plan}/pause', [ThriftPlanController::class, 'pause'])->name('plans.pause');
    Route::post('plans/{plan}/resume', [ThriftPlanController::class, 'resume'])->name('plans.resume');
    Route::post('plans/{plan}/cancel', [ThriftPlanController::class, 'cancel'])->name('plans.cancel');
    Route::get('plans/{plan}/settlement', [FinancialWorkflowController::class, 'settlement'])->name('plans.settlement');
    Route::post('plans/{plan}/settlement/{action}', [FinancialWorkflowController::class, 'settlementConfirm'])->whereIn('action', ['close', 'prepare_termination', 'resolve_exception'])->name('plans.settlement.confirm');
    Route::get('reversals/{reversal}/replacement', [FinancialWorkflowController::class, 'replacement'])->name('reversals.replacement');
    Route::post('reversals/{reversal}/replacement/preview', [FinancialWorkflowController::class, 'replacementPreview'])->name('reversals.replacement.preview');
    Route::post('reversals/{reversal}/replacement', [FinancialWorkflowController::class, 'replacementStore'])->name('reversals.replacement.store');
    Route::post('cash-disbursements/{execution}/return', [CashRecoveryController::class, 'disbursement'])->middleware('fresh')->name('cash-disbursements.return');
    Route::get('plans/{plan}', [ThriftPlanController::class, 'show'])->name('plans.show');

    Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('customers/{customer}/statements', [FinancialArtifactController::class, 'statement'])->name('customers.statements.issue');
    Route::post('reports/{report}/exports', [FinancialArtifactController::class, 'export'])->name('reports.export');
    Route::get('financial-artifacts/{artifact}', [FinancialArtifactController::class, 'show'])->name('financial-artifacts.show');
    Route::get('financial-artifacts/{artifact}/download', [FinancialArtifactController::class, 'download'])->name('financial-artifacts.download');
    Route::post('financial-artifacts/{artifact}/cancel', [FinancialArtifactController::class, 'cancel'])->name('financial-artifacts.cancel');
    Route::post('financial-artifacts/{artifact}/retry', [FinancialArtifactController::class, 'retry'])->name('financial-artifacts.retry');
    Route::post('financial-artifacts/{artifact}/hold', [FinancialArtifactController::class, 'hold'])->middleware('fresh')->name('financial-artifacts.hold');

    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('transactions', [LedgerTransactionController::class, 'index'])->middleware('throttle:120,1,ledger-read')->name('transactions.index');
    Route::get('transactions/{transaction}', [LedgerTransactionController::class, 'show'])->middleware('throttle:120,1,ledger-read')->name('transactions.show');
    Route::post('ledger/incidents/{reference}/resolve', [LedgerTransactionController::class, 'resolveIncident'])->whereUuid('reference')->middleware('fresh')->name('ledger.incidents.resolve');
    Route::get('customers/{customer}/withdrawals/create', [WithdrawalController::class, 'create'])->name('customers.withdrawals.create');
    Route::post('customers/{customer}/withdrawals/preview', [WithdrawalController::class, 'preview'])->name('customers.withdrawals.preview');
    Route::post('customers/{customer}/withdrawals', [WithdrawalController::class, 'store'])->name('customers.withdrawals.store');
    Route::get('withdrawals/attempts/{reference}', [WithdrawalController::class, 'attempt'])->name('withdrawals.attempts.show');
    Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
    Route::post('withdrawals/{withdrawal}/approve', [WithdrawalController::class, 'approve'])->middleware('fresh')->name('withdrawals.approve');
    Route::post('withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject'])->middleware('fresh')->name('withdrawals.reject');
    Route::post('withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel'])->name('withdrawals.cancel');
    Route::post('withdrawals/{withdrawal}/revoke', [WithdrawalController::class, 'revoke'])->middleware('fresh')->name('withdrawals.revoke');
    Route::get('cash-disbursements', [CashDisbursementController::class, 'index'])->name('cash-disbursements.index');
    Route::post('fee-refunds/{refund}/cash', [CashDisbursementController::class, 'refund'])->middleware('fresh')->name('fee-refunds.cash');
    Route::post('earnings-draws', [CashDisbursementController::class, 'draw'])->middleware('fresh')->name('earnings-draws.start');
    Route::post('cash-disbursements/{execution}/handoff', [CashDisbursementController::class, 'handoff'])->middleware('fresh')->name('cash-disbursements.handoff');
    Route::post('cash-disbursements/{execution}/acknowledge', [CashDisbursementController::class, 'acknowledge'])->name('cash-disbursements.acknowledge');
    Route::post('withdrawals/{withdrawal}/cash/start', [CashExecutionController::class, 'start'])->middleware('fresh')->name('withdrawals.cash.start');
    Route::post('cash-executions/{execution}/return', [CashRecoveryController::class, 'record'])->middleware('fresh')->name('cash-executions.return');
    Route::get('cash-recovery-preview/{kind}/{execution}', [CashRecoveryController::class, 'preview'])->whereIn('kind', ['withdrawal', 'disbursement'])->name('cash-recoveries.preview');
    Route::post('cash-recoveries/{recovery}/acknowledge', [CashRecoveryController::class, 'acknowledge'])->name('cash-recoveries.acknowledge');
    Route::post('cash-executions/{execution}/handoff', [CashExecutionController::class, 'handoff'])->middleware('fresh')->name('cash-executions.handoff');
    Route::post('cash-executions/{execution}/not-delivered', [CashExecutionController::class, 'notDelivered'])->middleware('fresh')->name('cash-executions.not-delivered');
    Route::post('cash-executions/{execution}/acknowledge', [CashExecutionController::class, 'acknowledge'])->name('cash-executions.acknowledge');

    // Simulated bank-transfer rail (Module 08)
    Route::get('customers/{customer}/payout-destinations', [BankPayoutDestinationController::class, 'index'])->name('customers.payout-destinations.index');
    Route::post('customers/{customer}/payout-destinations', [BankPayoutDestinationController::class, 'store'])->middleware('throttle:10,1,payout-destination-register')->name('customers.payout-destinations.store');
    Route::post('payout-destinations/{destination}/verify', [BankPayoutDestinationController::class, 'verify'])->middleware('fresh')->name('payout-destinations.verify');
    Route::post('payout-destinations/{destination}/reject', [BankPayoutDestinationController::class, 'reject'])->middleware('fresh')->name('payout-destinations.reject');
    Route::post('payout-destinations/{destination}/revoke', [BankPayoutDestinationController::class, 'revoke'])->middleware('fresh')->name('payout-destinations.revoke');
    Route::post('withdrawals/{withdrawal}/bank/start', [BankPayoutController::class, 'start'])->middleware('fresh')->name('withdrawals.bank.start');
    Route::post('bank-payout-attempts/{attempt}/check', [BankPayoutController::class, 'check'])->middleware('throttle:30,1,bank-payout-check')->name('bank-payout-attempts.check');

    // Gated financial corrections (Module 09)
    Route::get('reversals', [ReversalController::class, 'index'])->name('reversals.index');
    Route::post('ledger-postings/{posting}/reversals/preview', [ReversalController::class, 'preview'])->name('reversals.preview');
    Route::post('ledger-postings/{posting}/reversals', [ReversalController::class, 'store'])->name('reversals.store');
    Route::get('reversals/attempts/{reference}', [ReversalController::class, 'attempt'])->name('reversals.attempts.show');
    Route::get('reversals/{reversal}', [ReversalController::class, 'show'])->name('reversals.show');
    Route::get('reversals/{reversal}/review-preview', [ReversalController::class, 'reviewPreview'])->name('reversals.review-preview');
    Route::post('reversals/{reversal}/approve', [ReversalController::class, 'approve'])->middleware('fresh')->name('reversals.approve');
    Route::post('reversals/{reversal}/reject', [ReversalController::class, 'reject'])->middleware('fresh')->name('reversals.reject');
    Route::post('reversals/{reversal}/cancel', [ReversalController::class, 'cancel'])->name('reversals.cancel');
    Route::post('reversals/{reversal}/evidence', [ReversalController::class, 'evidenceStore'])->middleware('throttle:20,1,reversal-evidence')->name('reversals.evidence.store');
    Route::get('reversals/{reversal}/evidence/{file}/link', [ReversalController::class, 'evidenceLink'])->whereNumber('file')->name('reversals.evidence.link');
    Route::get('reversals/{reversal}/evidence/{file}/download', [ReversalController::class, 'evidenceDownload'])->whereNumber('file')->middleware('signed')->name('reversals.evidence.download');

    // Cash collections, thrift cards, and reconciliation (Module 07)
    Route::middleware('collections.enabled')->group(function (): void {
        Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
        Route::get('customers/{customer}/collections/create', [CollectionController::class, 'create'])->name('customers.collections.create');
        Route::get('customers/{customer}/collections/time-options', [CollectionController::class, 'timeOptions'])->name('customers.collections.time-options');
        Route::post('customers/{customer}/collections/preview', [CollectionController::class, 'preview'])->name('customers.collections.preview');
        Route::post('customers/{customer}/collections', [CollectionController::class, 'store'])->name('customers.collections.store');
        Route::get('collections/attempts/{reference}', [CollectionController::class, 'attempt'])->name('collections.attempts.show');
        Route::get('collections/{receipt}', [CollectionController::class, 'show'])->name('collections.show');
        Route::get('collection-batches', [ReconciliationController::class, 'index'])->name('collection-batches.index');
        Route::get('collection-batches/{batch}', [ReconciliationController::class, 'show'])->name('collection-batches.show');
        Route::post('collection-batches/{batch}/remittances', [ReconciliationController::class, 'remit'])->name('collection-batches.remittances.store');
        Route::post('collection-batches/{batch}/review', [ReconciliationController::class, 'review'])->name('collection-batches.review');
        Route::post('collection-batches/{batch}/exceptions', [ReconciliationController::class, 'reportException'])->name('collection-batches.exceptions.store');
        Route::post('collection-batches/{batch}/exceptions/{exception}/resolve', [ReconciliationController::class, 'resolveException'])->name('collection-batches.exceptions.resolve');
        Route::post('collection-batches/{batch}/exceptions/{exception}/progress', [ReconciliationController::class, 'progressException'])->name('collection-batches.exceptions.progress');
        Route::post('collection-batches/{batch}/exceptions/{exception}/reopen', [ReconciliationController::class, 'reopenException'])->name('collection-batches.exceptions.reopen');
    });

    // Customer Invitation Management (CAM-T06)
    Route::post('customers/{customer}/invitation/resend', [CustomerInvitationController::class, 'resend'])->name('customers.invitations.resend');
    Route::post('customers/{customer}/invitation/correct-email', [CustomerInvitationController::class, 'correctEmail'])->name('customers.invitations.correct-email');
    Route::post('customers/{customer}/invitation/cancel', [CustomerInvitationController::class, 'cancel'])->name('customers.invitations.cancel');

    // Agent Registration and Attempts (CAM-T05)
    Route::get('agents/create', [AgentRegistrationController::class, 'create'])->name('agents.create');
    Route::post('agents', [AgentRegistrationController::class, 'store'])->name('agents.store');
    Route::get('agents/attempts/{reference}', [AgentRegistrationController::class, 'showAttempt'])->name('agents.attempts.show');

    Route::get('agents', [AgentDirectoryController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}/status', [AgentStatusController::class, 'edit'])->name('agents.status.edit');
    Route::patch('agents/{agent}/status', [AgentStatusController::class, 'update'])->name('agents.status.update');
    Route::get('agents/{agent}/lifecycle', [AgentLifecycleController::class, 'show'])->name('agents.lifecycle.show');
    Route::get('agents/{agent}/lifecycle/operations/{attempt_reference}', [AgentLifecycleController::class, 'operation'])->whereUuid('attempt_reference')->name('agents.lifecycle.operation');
    Route::middleware('fresh')->group(function (): void {
        Route::post('agents/{agent}/lifecycle/suspend', [AgentLifecycleController::class, 'suspend'])->name('agents.lifecycle.suspend');
        Route::post('agents/{agent}/lifecycle/restore', [AgentLifecycleController::class, 'restore'])->name('agents.lifecycle.restore');
        Route::post('agents/{agent}/lifecycle/start-offboarding', [AgentLifecycleController::class, 'startOffboarding'])->name('agents.lifecycle.start-offboarding');
        Route::post('agents/{agent}/lifecycle/transfer-owner', [AgentLifecycleController::class, 'transferOwner'])->name('agents.lifecycle.transfer-owner');
        Route::post('agents/{agent}/lifecycle/cancel-offboarding', [AgentLifecycleController::class, 'cancelOffboarding'])->name('agents.lifecycle.cancel-offboarding');
        Route::post('agents/{agent}/lifecycle/complete-offboarding', [AgentLifecycleController::class, 'completeOffboarding'])->name('agents.lifecycle.complete-offboarding');
        Route::post('agents/{agent}/lifecycle/return', [AgentLifecycleController::class, 'returnToService'])->name('agents.lifecycle.return');
    });
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

    Route::get('customer/dashboard', [DashboardController::class, 'index'])
        ->middleware('role:customer')
        ->name('customer.dashboard');

    Route::get('agent/dashboard', [DashboardController::class, 'index'])
        ->middleware('role:agent')
        ->name('agent.dashboard');

    Route::get('admin/dashboard', [DashboardController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.dashboard');

    // Admin Lockout & Abuse Visibility (AUTH-061, AUTH-063)
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::prefix('financial-periods')->name('financial-periods.')->group(function (): void {
            Route::get('/', [FinancialPeriodController::class, 'index'])->name('index');
            Route::post('/', [FinancialPeriodController::class, 'open'])->middleware('fresh')->name('open');
            Route::post('{month}/close', [FinancialPeriodController::class, 'close'])->middleware('fresh')
                ->where('month', '[0-9]{4}-[0-9]{2}')->name('close');
            Route::post('{month}/reopen', [FinancialPeriodController::class, 'reopen'])->middleware('fresh')
                ->where('month', '[0-9]{4}-[0-9]{2}')->name('reopen');
        });
        Route::prefix('business-settings')->name('business-settings.')->group(function (): void {
            Route::get('/', [BusinessSettingsController::class, 'index'])->name('index');
            Route::post('drafts', [BusinessSettingsController::class, 'store'])->middleware('throttle:30,1')->name('drafts.store');
            Route::patch('drafts/{draft}', [BusinessSettingsController::class, 'update'])->whereNumber('draft')->name('drafts.update');
            Route::post('drafts/{draft}/preview', [BusinessSettingsController::class, 'preview'])->whereNumber('draft')->name('drafts.preview');
            Route::post('drafts/{draft}/publish', [BusinessSettingsController::class, 'publish'])->whereNumber('draft')->middleware(['fresh', 'throttle:10,1'])->name('drafts.publish');
            Route::post('drafts/{draft}/discard', [BusinessSettingsController::class, 'discard'])->whereNumber('draft')->name('drafts.discard');
            Route::post('versions/{configuration}/cancel', [BusinessSettingsController::class, 'cancel'])->whereNumber('configuration')->middleware('fresh')->name('versions.cancel');
            Route::post('versions/{configuration}/rollback', [BusinessSettingsController::class, 'rollback'])->whereNumber('configuration')->name('versions.rollback');
            Route::get('operations/{operation}', [BusinessSettingsController::class, 'operation'])->whereUuid('operation')->name('operations.show');
        });
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/{event}', [AuditController::class, 'show'])->whereUlid('event')->name('audit.show');
        Route::get('security/cases', [SecurityCaseController::class, 'index'])->name('security.index');
        Route::get('security/cases/{case:case_reference}', [SecurityCaseController::class, 'show'])->name('security.show');
        Route::patch('security/cases/{case:case_reference}', [SecurityCaseController::class, 'update'])->name('security.update');
        Route::get('lockouts', [LockoutController::class, 'index'])->name('lockouts.index');
        Route::post('lockouts/{user}/unlock', [LockoutController::class, 'unlock'])->name('lockouts.unlock');

        Route::get('access', [AdminAccessController::class, 'index'])->name('access.index');
        Route::get('access/{admin}', [AdminAccessController::class, 'show'])->name('access.show');
        Route::put('access/{admin}/permissions', [AdminAccessController::class, 'update'])
            ->middleware('fresh')
            ->name('access.permissions.update');

        Route::get('charges', [ManualChargeController::class, 'index'])->name('charges.index');
        Route::post('charges/categories', [ManualChargeController::class, 'publish'])->name('charges.publish');
        Route::get('charges/attempts/{reference}', [ManualChargeController::class, 'status'])->whereUuid('reference')->name('charges.status');
        Route::post('charges/preview', [ManualChargeController::class, 'preview'])->name('charges.preview');
        Route::post('charges', [ManualChargeController::class, 'assess'])->name('charges.assess');
        Route::post('fees/obligations/{obligation}/refund', [FeeRefundController::class, 'store'])->middleware('fresh')->name('fees.refunds.store');
        Route::get('fees', [FeeOverviewController::class, 'index'])->name('fees.index');
        Route::post('fees/obligations/{obligation}/attempts/prepare', [FeeActionAttemptController::class, 'prepare'])->middleware('fresh')->name('fees.obligations.attempts.prepare');
        Route::post('fees/obligations/{obligation}/attempts/cancel', [FeeActionAttemptController::class, 'cancel'])->middleware('fresh')->name('fees.obligations.attempts.cancel');
        Route::get('fees/obligations/{obligation}/attempts/{attemptReference}', [FeeActionAttemptController::class, 'status'])->whereUuid('attemptReference')->name('fees.obligations.attempts.status');
        Route::get('fees/obligations/{obligation}/savings-sources', [FeeSavingsApplicationController::class, 'sources'])->name('fees.obligations.savings-sources');
        Route::post('fees/obligations/{obligation}/savings-preview', [FeeSavingsApplicationController::class, 'preview'])->name('fees.obligations.savings-preview');
        Route::post('fees/obligations/{obligation}/apply-savings', [FeeSavingsApplicationController::class, 'store'])->middleware('fresh')->name('fees.obligations.apply-savings');
        Route::get('fees/obligations/{obligation}/savings-status/{attemptReference}', [FeeSavingsApplicationController::class, 'status'])->whereUuid('attemptReference')->name('fees.obligations.savings-status');
        Route::get('fees/obligations/{obligation}/action-status/{attemptReference}', [FeeOverviewController::class, 'actionStatus'])->whereUuid('attemptReference')->name('fees.obligations.action-status');

        Route::post('fees/obligations/{obligation}/waive', [FeeOverviewController::class, 'waive'])
            ->middleware('fresh')
            ->name('fees.obligations.waive');
        Route::post('fees/obligations/{obligation}/correct', [FeeOverviewController::class, 'correct'])
            ->middleware('fresh')
            ->name('fees.obligations.correct');

        // Registration Fee Rules (CAM-T06)
        Route::get('fees/registration', [RegistrationFeeRuleController::class, 'index'])->name('fees.registration.index');
        Route::post('fees/registration/preview', [RegistrationFeeRuleController::class, 'preview'])->name('fees.registration.preview');
        Route::post('fees/registration/{feeRule}/retirement-preview', [RegistrationFeeRuleController::class, 'previewRetirement'])->name('fees.registration.retirement-preview');
        Route::post('fees/registration/{feeRule}/retire', [RegistrationFeeRuleController::class, 'retire'])->middleware('fresh')->name('fees.registration.retire');
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

// Signed provider callbacks authenticate by signature, not by session.
Route::post('payout-callbacks/{provider}', BankPayoutCallbackController::class)->middleware('throttle:240,1,payout-callbacks')->name('payout-callbacks.store');

require __DIR__.'/settings.php';
