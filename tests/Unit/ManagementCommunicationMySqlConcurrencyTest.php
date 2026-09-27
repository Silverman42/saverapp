<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\Invitation;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use App\Services\InvitationDeliveryIssues;
use App\Services\NotificationPipeline;
use App\Services\PermissionManagementService;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires isolated saverapp_audit_testing MySQL.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    Queue::fake();
    [$this->admin, $this->customer, $this->agent] = $this->createLifecycleFixture();
    $user = $this->customer->user;
    $user->forceFill(['account_state' => AccountState::Invited])->save();
    $invitation = Invitation::create(['user_id' => $user->id, 'role' => 'customer', 'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized, 'token_hash' => hash('sha256', 'race-challenge'), 'generation' => 1,
        'status' => InvitationStatus::PendingDelivery, 'delivery_status' => DeliveryStatus::Pending,
        'expires_at' => now()->addDay(), 'invited_by_user_id' => $this->agent->user_id]);
    app(InvitationDeliveryIssues::class)->record($invitation->id, 1, 'queue_unavailable', 0);
});

function communicationRaceGuard(): void
{
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe communication worker database.');
    }
    Queue::fake();
}

function communicationMaterialize(int $id): Closure
{
    return static function () use ($id): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe communication worker database.');
        }
        Queue::fake();
        app(NotificationPipeline::class)->materialize($id);

        return DB::table('notification_inbox_intents')->where('id', $id)->value('status');
    };
}

test('mysql duplicate materialization creates one logical notice attempt and audit', function () {
    $id = (int) DB::table('notification_inbox_intents')->where('recipient_user_id', $this->admin->id)->value('id');
    $results = Concurrency::driver('process')->run([communicationMaterialize($id), communicationMaterialize($id)]);
    expect($results)->each->toBeIn(['pending', 'delivered']);
    $this->assertDatabaseHas('notification_inbox_intents', ['id' => $id, 'status' => 'delivered']);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('notification_inbox_attempts', 1);
    $this->assertDatabaseCount('customer_assignments', 1);
    $this->assertDatabaseHas('canonical_audit_events', ['event_type' => 'customer.delivery_attempt']);
});

test('mysql reassignment racing materialization revokes former scope and routes the unresolved issue', function () {
    $this->admin->givePermissionTo(AdminPermission::CustomersReassign);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $id = (int) DB::table('notification_inbox_intents')->where('recipient_user_id', $this->agent->user_id)->value('id');
    $preview = app(CustomerReassignmentService::class)->preview($this->admin, $this->customer, $replacement->id);
    $input = ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
        'target_agent_id' => $replacement->id, 'reason' => 'Service handover', 'customer_explanation' => 'Your service contact changed.'];
    $actorId = $this->admin->id;
    $customerId = $this->customer->id;
    Concurrency::driver('process')->run([communicationMaterialize($id), communicationReassignment($actorId, $customerId, $input)]);
    if (DB::table('notification_inbox_intents')->where('id', $id)->value('status') === 'pending') {
        $this->travel(3)->minutes();
        app(NotificationPipeline::class)->materialize($id);
    }
    expect(app(NotificationPipeline::class)->isRecipientEligible($id))->toBeFalse();
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBeIn(['delivered', 'suppressed']);
    $this->assertDatabaseHas('invitation_issue_notification_intents', ['recipient_user_id' => $replacement->user_id]);
    $this->assertDatabaseCount('customer_handover_operations', 1);
});

test('mysql permission revocation racing materialization removes protected retrieval', function () {
    $operator = User::factory()->admin()->withTwoFactor()->create();
    $operator->givePermissionTo([AdminPermission::AdminsManage, AdminPermission::CustomersManage]);
    $targetId = $this->admin->id;
    $operatorId = $operator->id;
    $version = $this->admin->permission_version;
    $id = (int) DB::table('notification_inbox_intents')->where('recipient_user_id', $targetId)->value('id');
    Concurrency::driver('process')->run([communicationMaterialize($id), communicationRevocation($operatorId, $targetId, $version)]);
    if (DB::table('notification_inbox_intents')->where('id', $id)->value('status') === 'pending') {
        $this->travel(3)->minutes();
        app(NotificationPipeline::class)->materialize($id);
    }
    expect(app(NotificationPipeline::class)->isRecipientEligible($id))->toBeFalse();
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBeIn(['delivered', 'suppressed']);
    $this->assertDatabaseCount('invitation_delivery_issues', 1);
});

/** @param array<string, mixed> $input */
function communicationReassignment(int $actorId, int $customerId, array $input): Closure
{
    return static function () use ($actorId, $customerId, $input): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe communication worker database.');
        }
        Queue::fake();
        app(CustomerReassignmentService::class)->execute(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $input);

        return 'committed';
    };
}

function communicationRevocation(int $operatorId, int $targetId, int $version): Closure
{
    return static function () use ($operatorId, $targetId, $version): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe communication worker database.');
        }
        Queue::fake();
        app(PermissionManagementService::class)->updatePermissions(User::findOrFail($operatorId), User::findOrFail($targetId), [], 'Remove management access.', $version);

        return 'revoked';
    };
}
