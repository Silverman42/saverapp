<?php

use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the declared capacity dataset (10,000 Customers, 30 Agents, 20,000 plans, 2,000,000 slots) around one real fixture plan.
 *
 * @return list<int> Agent profile ids; the first is the fixture Agent.
 */
function collectionLoadProfileDataset(User $admin, CustomerProfile $customer, AgentProfile $agent, ThriftPlan $plan, CarbonImmutable $today): array
{
    $timestamp = now();
    $agentIds = [$agent->id];
    $agentUser = $agent->user->getAttributes();
    $agentProfile = $agent->getAttributes();
    for ($index = 1; $index < 30; $index++) {
        $userId = 100000 + $index;
        $profileId = 100000 + $index;
        DB::table('users')->insert([...$agentUser, 'id' => $userId,
            'name' => 'Load Agent '.$index, 'email' => 'load-agent-'.$index.'@example.test',
            'email_normalized' => 'load-agent-'.$index.'@example.test',
            'created_at' => $timestamp, 'updated_at' => $timestamp]);
        DB::table('agent_profiles')->insert([...$agentProfile, 'id' => $profileId,
            'user_id' => $userId, 'agent_id' => 'AGT-LOAD-'.$index,
            'phone' => '+234810'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
            'phone_normalized' => '+234810'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
            'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $agentIds[] = $profileId;
    }

    $customerUser = $customer->user->getAttributes();
    $customerProfile = $customer->getAttributes();
    $assignment = $customer->currentAssignment->getAttributes();
    for ($first = 1; $first < 10000; $first += 500) {
        $users = $profiles = $assignments = [];
        for ($index = $first; $index < min($first + 500, 10000); $index++) {
            $id = 200000 + $index;
            $email = 'load-customer-'.$index.'@example.test';
            $phone = '+234820'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            $users[] = [...$customerUser, 'id' => $id, 'name' => 'Load Customer '.$index,
                'email' => $email, 'email_normalized' => $email,
                'created_at' => $timestamp, 'updated_at' => $timestamp];
            $profiles[] = [...$customerProfile, 'id' => $id, 'user_id' => $id,
                'customer_id' => 'CUS-LOAD-'.$index, 'phone' => $phone,
                'phone_normalized' => $phone, 'created_by_user_id' => $admin->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp];
            $assignments[] = [...$assignment, 'id' => $id,
                'customer_profile_id' => $id, 'agent_profile_id' => $agentIds[$index % 30],
                'assigned_by_user_id' => $admin->id, 'created_at' => $timestamp,
                'updated_at' => $timestamp];
        }
        DB::table('users')->insert($users);
        DB::table('customer_profiles')->insert($profiles);
        DB::table('customer_assignments')->insert($assignments);
    }

    $terms = $plan->currentTermsRevision();
    $planTemplate = $plan->getAttributes();
    $snapshotTemplate = $terms->feeSnapshot->getAttributes();
    $termsTemplate = $terms->getAttributes();
    DB::table('plan_terms_revisions')->where('id', $terms->id)
        ->update(['contribution_days' => 100, 'expected_gross_kobo' => 20000000]);
    $slotDates = [];
    for ($ordinal = 0; $ordinal < 100; $ordinal++) {
        $slotDates[] = $today->subDays(50)->addDays($ordinal)->toDateString();
    }
    $targetSlots = [];
    for ($ordinal = 3; $ordinal <= 100; $ordinal++) {
        $targetSlots[] = ['thrift_plan_id' => $plan->id, 'plan_terms_revision_id' => $terms->id,
            'ordinal' => $ordinal, 'active_ordinal' => $ordinal,
            'due_date' => $today->addDays($ordinal - 1)->toDateString(),
            'expected_amount_kobo' => 200000, 'created_at' => $timestamp, 'updated_at' => $timestamp];
    }
    DB::table('contribution_slots')->insert($targetSlots);

    // The remaining plans have one hundred immutable slots each; inserts stay bounded.
    for ($first = 1; $first < 20000; $first += 500) {
        $plans = $snapshots = $revisions = [];
        for ($index = $first; $index < min($first + 500, 20000); $index++) {
            $customerId = $index === 1 ? $customer->id : 200000 + intdiv($index, 2);
            $id = 300000 + $index;
            $reference = 'PLN-LOAD-'.$index;
            $active = $index > 1 && $index % 2 === 0;
            $plans[] = [...$planTemplate, 'id' => $id, 'plan_id' => $reference,
                'customer_profile_id' => $customerId,
                'open_customer_profile_id' => $active ? $customerId : null,
                'status' => $active ? 'active' : 'closed',
                'created_at' => $timestamp, 'updated_at' => $timestamp];
            $snapshots[] = [...$snapshotTemplate, 'id' => $id,
                'customer_profile_id' => $customerId, 'source_id' => $reference,
                'created_at' => $timestamp, 'updated_at' => $timestamp];
            $revisions[] = [...$termsTemplate, 'id' => $id, 'thrift_plan_id' => $id,
                'fee_snapshot_id' => $id, 'start_date' => $slotDates[0],
                'contribution_days' => 100, 'expected_gross_kobo' => 20000000,
                'created_at' => $timestamp, 'updated_at' => $timestamp];
        }
        DB::table('thrift_plans')->insert($plans);
        DB::table('fee_snapshots')->insert($snapshots);
        DB::table('plan_terms_revisions')->insert($revisions);
    }

    for ($first = 1; $first < 20000; $first += 50) {
        $slots = [];
        for ($index = $first; $index < min($first + 50, 20000); $index++) {
            $id = 300000 + $index;
            foreach ($slotDates as $ordinal => $date) {
                $slots[] = ['thrift_plan_id' => $id, 'plan_terms_revision_id' => $id,
                    'ordinal' => $ordinal + 1, 'active_ordinal' => $ordinal + 1,
                    'due_date' => $date, 'expected_amount_kobo' => 200000,
                    'created_at' => $timestamp, 'updated_at' => $timestamp];
            }
        }
        DB::table('contribution_slots')->insert($slots);
    }

    expect(DB::table('customer_profiles')->count())->toBe(10000)
        ->and(DB::table('agent_profiles')->count())->toBe(30)
        ->and(DB::table('thrift_plans')->count())->toBe(20000)
        ->and(DB::table('contribution_slots')->count())->toBe(2000000);

    return $agentIds;
}
