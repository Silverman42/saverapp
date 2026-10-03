<?php

use App\Enums\AdminPermission;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\User;

require_once __DIR__.'/CollectionFixtures.php';

function collectionInvestigationFixture(object $test, bool $openCase = true): array
{
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $quote = $test->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $test->post(route('customers.collections.store', $customer->customer_id), [...$payload, 'preview_fingerprint' => $quote['preview_fingerprint']])->assertRedirect();
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $batch = CollectionBatch::query()->sole();
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    if ($openCase) {
        $test->actingAs($admin)->post(route('collection-batches.review', $batch), [
            'batch_version' => $batch->version, 'reason' => 'Original counted cash awaits handoff.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    return [$agent, $customer, $admin, $batch->fresh(), $openCase ? CollectionException::query()->sole() : null, $date];
}
