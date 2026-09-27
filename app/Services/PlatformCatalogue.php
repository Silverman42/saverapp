<?php

namespace App\Services;

use App\Jobs\DeliverAgentInvitationJob;
use App\Jobs\DeliverAgentLifecycleNotificationIntent;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Jobs\DeliverReversalNotificationIntent;
use App\Jobs\DeliverWithdrawalNotificationIntent;
use App\Jobs\ExpirePendingTwoFactorSetup;
use App\Jobs\MaterializeNotificationIntent;
use App\Jobs\ProjectAuditEvent;
use App\Models\CustomerProfile;
use App\Support\PlatformBlocked;
use Illuminate\Http\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlatformCatalogue
{
    public const VERSION = 1;

    /** @var array<class-string, string> */
    public const LOCAL_INTENT_JOBS = [
        DeliverCustomerHandoverNotice::class => 'handover',
        DeliverAgentStatusNotificationIntent::class => 'agent_status',
        DeliverAgentLifecycleNotificationIntent::class => 'agent_lifecycle',
        DeliverCollectionNotificationIntent::class => 'collection',
        DeliverCustomerStatusNotificationIntent::class => 'customer_status',
        DeliverPlanNotificationIntent::class => 'plan',
        DeliverProfileNotificationIntent::class => 'profile',
        DeliverReversalNotificationIntent::class => 'reversal',
        DeliverWithdrawalNotificationIntent::class => 'withdrawal',
    ];

    public function isLocalRecoveryJob(object $job): bool
    {
        if ($job instanceof ProjectAuditEvent || $job instanceof MaterializeNotificationIntent) {
            return true;
        }
        $family = self::LOCAL_INTENT_JOBS[$job::class] ?? null;
        if ($family === null || ! property_exists($job, 'intentId') || ! is_int($job->intentId)) {
            return false;
        }
        $owner = DB::table(NotificationCatalogue::OWNERS[$family]['table'])->where('id', $job->intentId)->first();

        return $owner === null || ($owner->channel ?? 'database') === 'database';
    }

    /** @var array<string, array{class: string, owner: string, dependencies: list<string>, recovery_tier: string}> */
    public const OPERATIONS = [
        'read' => ['class' => 'read', 'owner' => 'Modules 01–15', 'dependencies' => ['database', 'authorization'], 'recovery_tier' => 'A'],
        'financial' => ['class' => 'financial', 'owner' => 'Modules 04–10', 'dependencies' => ['database', 'authorization', 'ledger', 'audit', 'configuration', 'owning_workflow'], 'recovery_tier' => 'A'],
        'mutation' => ['class' => 'mutation', 'owner' => 'Modules 02–04/13–15', 'dependencies' => ['database', 'authorization', 'audit', 'owning_workflow'], 'recovery_tier' => 'A'],
        'derived' => ['class' => 'derived', 'owner' => 'Modules 10/14', 'dependencies' => ['database', 'canonical_source'], 'recovery_tier' => 'C'],
        'external' => ['class' => 'external', 'owner' => 'Module 13', 'dependencies' => ['database', 'durable_intent', 'recipient_scope', 'channel_owner'], 'recovery_tier' => 'B'],
    ];

    /** @var array<string, string> */
    public const SERVICES = [
        'CollectionService' => 'financial', 'CollectionLedgerService' => 'financial', 'LedgerPostingService' => 'financial',
        'WithdrawalService' => 'financial', 'ReversalService' => 'financial', 'FeeObligationService' => 'financial',
        'CustomerRegistrationService' => 'financial', 'CustomerStatusManagementService' => 'financial', 'CustomerLifecycleService' => 'financial', 'ThriftPlanService' => 'financial',
        'AgentRegistrationService' => 'mutation', 'AgentStatusManagementService' => 'mutation', 'RegistrationFeeService' => 'mutation',
        'EmailReservationService' => 'mutation', 'CustomerHandoverNotifications' => 'mutation', 'AgentLifecycleService' => 'mutation', 'CustomerReassignmentService' => 'mutation', 'CustomerRecoveryService' => 'mutation',
        'ProfileManagementService' => 'mutation', 'PhoneChangeService' => 'mutation', 'EmailChangeService' => 'mutation',
        'PermissionManagementService' => 'mutation', 'InvitationManagementService' => 'mutation', 'CustomerInvitationManagementService' => 'mutation',
        'CustomerNameCorrectionService' => 'mutation', 'NotificationInbox' => 'mutation', 'SecurityCaseService' => 'mutation', 'BusinessSettings' => 'mutation',
        'AuditProjection' => 'derived', 'LedgerTransactionProjectionService' => 'derived', 'NotificationPipeline' => 'external',
    ];

    /** @var array<string, string> */
    public const COMMANDS = [
        'customers:expire-recovery' => 'mutation',
        'platform:replay' => 'mutation',
        'collections:freeze-batches' => 'financial', 'withdrawals:expire' => 'financial',
        'notifications:drain' => 'external', 'audit:drain' => 'derived', 'audit:rebuild' => 'derived',
        'ledger:rebuild-transactions' => 'derived', 'business:activate-settings' => 'mutation', 'authz:expire-restrictions' => 'mutation',
    ];

    /** @return array{class: string, owner: string, dependencies: list<string>, recovery_tier: string} */
    public function definition(string $operation): array
    {
        return self::OPERATIONS[$operation] ?? throw new InvalidArgumentException('Unknown platform operation class.');
    }

    /** @var array<string, string> */
    public const HTTP_ACTIONS = [
        'customers.reassignment.preview' => 'read', 'customers.reassignment.store' => 'mutation', 'customers.recovery.store' => 'mutation', 'customers.recovery.update' => 'mutation', 'customer-recovery.activate' => 'mutation',
        'admin.access.permissions.update' => 'mutation',
        'admin.business-settings.drafts.discard' => 'mutation',
        'admin.business-settings.drafts.preview' => 'mutation',
        'admin.business-settings.drafts.publish' => 'mutation',
        'admin.business-settings.drafts.store' => 'mutation',
        'admin.business-settings.drafts.update' => 'mutation',
        'admin.business-settings.versions.cancel' => 'mutation',
        'admin.business-settings.versions.rollback' => 'mutation',
        'admin.fees.obligations.correct' => 'financial',
        'admin.fees.obligations.waive' => 'financial',
        'admin.fees.registration.store' => 'mutation',
        'admin.lockouts.unlock' => 'mutation',
        'admin.security.update' => 'mutation',
        'agents.invitations.cancel' => 'mutation',
        'agents.invitations.correct-email' => 'mutation',
        'agents.invitations.resend' => 'mutation',
        'agents.phone-corrections.store' => 'mutation',
        'agents.phone.self' => 'mutation',
        'agents.status.update' => 'mutation',
        'agents.lifecycle.suspend' => 'mutation',
        'agents.lifecycle.restore' => 'mutation',
        'agents.lifecycle.start-offboarding' => 'mutation',
        'agents.lifecycle.transfer-owner' => 'mutation',
        'agents.lifecycle.cancel-offboarding' => 'mutation',
        'agents.lifecycle.complete-offboarding' => 'mutation',
        'agents.lifecycle.return' => 'mutation',
        'agents.store' => 'mutation',
        'agents.update' => 'mutation',
        'boost.browser-logs' => 'read',
        'collection-batches.exceptions.reopen' => 'mutation',
        'collection-batches.exceptions.resolve' => 'mutation',
        'collection-batches.exceptions.store' => 'mutation',
        'collection-batches.remittances.store' => 'financial',
        'collection-batches.review' => 'mutation',
        'customers.collections.preview' => 'read',
        'customers.collections.store' => 'financial',
        'customers.invitations.cancel' => 'mutation',
        'customers.invitations.correct-email' => 'mutation',
        'customers.invitations.resend' => 'mutation',
        'customers.name-corrections.accept' => 'mutation',
        'customers.name-corrections.cancel' => 'mutation',
        'customers.name-corrections.reject' => 'mutation',
        'customers.name-corrections.store' => 'mutation',
        'customers.name.update' => 'mutation',
        'customers.phone-corrections.store' => 'mutation',
        'customers.phone.self' => 'mutation',
        'customers.plans.store' => 'financial',
        'customers.status.update' => 'financial',
        'customers.lifecycle.preview' => 'read',
        'customers.lifecycle.archive' => 'financial',
        'customers.lifecycle.restore' => 'financial',
        'customers.store' => 'financial',
        'customers.update' => 'mutation',
        'customers.withdrawals.preview' => 'read',
        'customers.withdrawals.store' => 'financial',
        'debugbar.cache.delete' => 'mutation',
        'debugbar.queries.explain' => 'mutation',
        'device-eviction.cancel' => 'mutation',
        'device-eviction.confirm' => 'mutation',
        'email-change.confirm' => 'mutation',
        'email-change.store' => 'mutation',
        'fresh-authentication.store' => 'mutation',
        'invitations.agent.activate' => 'mutation',
        'invitations.customer.activate' => 'mutation',
        'login.store' => 'mutation',
        'logout' => 'mutation',
        'customers.delivery.index' => 'read', 'agents.delivery.index' => 'read',
        'notifications.page-read' => 'mutation',
        'notifications.read' => 'mutation',
        'password.confirm.store' => 'mutation',
        'password.email' => 'mutation',
        'password.update' => 'mutation',
        'plans.cancel' => 'financial',
        'plans.card.annotations.store' => 'mutation',
        'plans.pause' => 'financial',
        'plans.resume' => 'financial',
        'plans.update' => 'financial',
        'profile.destroy' => 'mutation',
        'reversals.approve' => 'financial',
        'reversals.cancel' => 'financial',
        'reversals.preview' => 'read',
        'reversals.reject' => 'financial',
        'reversals.store' => 'financial',
        'sessions.destroy' => 'mutation',
        'sessions.destroy-all' => 'mutation',
        'sessions.destroy-others' => 'mutation',
        'two-factor.confirm' => 'mutation',
        'two-factor.disable' => 'mutation',
        'two-factor.enable' => 'mutation',
        'two-factor.enrolment.acknowledge' => 'mutation',
        'two-factor.enrolment.confirm' => 'mutation',
        'two-factor.login.store' => 'mutation',
        'two-factor.regenerate-recovery-codes' => 'mutation',
        'two-factor.replace' => 'mutation',
        'two-factor.replace.cancel' => 'mutation',
        'two-factor.replace.confirm' => 'mutation',
        'user-password.update' => 'mutation',
        'verification.send' => 'mutation',
        'verification.verify' => 'mutation',
        'withdrawals.approve' => 'financial',
        'withdrawals.cancel' => 'financial',
        'withdrawals.reject' => 'financial',
        'withdrawals.revoke' => 'financial',
    ];

    public function requestClass(Request $request): string
    {
        $route = app('router')->getRoutes()->match($request);
        $declared = self::HTTP_ACTIONS[$route->getName()] ?? null;
        if ($declared !== null) {
            return $declared;
        }
        if ($request->isMethodSafe() || $route->getActionName() === 'Illuminate\\Routing\\RedirectController') {
            return 'read';
        }

        throw new PlatformBlocked('platform_operation_unclassified');
    }

    public function jobClass(object $job): string
    {
        return $this->jobOperation($job::class);
    }

    public function jobOperation(string $jobClass): string
    {
        return match ($jobClass) {
            ProjectAuditEvent::class => 'derived',
            ExpirePendingTwoFactorSetup::class => 'mutation',
            DeliverAgentInvitationJob::class,
            DeliverAgentStatusNotificationIntent::class,
            DeliverCollectionNotificationIntent::class,
            DeliverCustomerHandoverNotice::class,
            DeliverCustomerInvitationJob::class,
            DeliverCustomerStatusNotificationIntent::class,
            DeliverPlanNotificationIntent::class,
            DeliverProfileNotificationIntent::class,
            DeliverReversalNotificationIntent::class,
            DeliverWithdrawalNotificationIntent::class,
            MaterializeNotificationIntent::class,
            SendQueuedNotifications::class => 'external',
            default => throw new PlatformBlocked('platform_operation_unclassified'),
        };
    }

    public function archivalWorkStatus(CustomerProfile $customer): string
    {
        $planWork = app(ThriftPlanService::class)->archivalWorkStatus($customer);
        if ($planWork !== 'passed') {
            return $planWork;
        }
        foreach (['jobs', 'failed_jobs'] as $table) {
            foreach (DB::table($table)->select('payload')->cursor() as $job) {
                $payload = json_decode($job->payload, true);
                $class = is_array($payload) ? ($payload['data']['commandName'] ?? null) : null;
                if (! is_string($class)) {
                    return 'unavailable';
                }
                try {
                    if ($this->jobOperation($class) === 'financial') {
                        return 'blocked';
                    }
                } catch (PlatformBlocked) {
                    return 'unavailable';
                }
            }
        }
        if (DB::table('platform_recovery_work')->whereNotIn('owner', BackgroundRecovery::OWNERS)->exists()) {
            return 'unavailable';
        }

        return 'passed';
    }
}
