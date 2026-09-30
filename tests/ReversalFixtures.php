<?php

use App\Enums\AdminPermission;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\ReversalService;
use Illuminate\Support\Str;

function approveReceiptCorrection(object $test, User $agent, object $customer, object $assignment, LedgerPostingGroup $original): object
{
    $service = app(ReversalService::class);
    $preview = $service->preview($agent, $original);
    $request = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $preview['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => $original->source_type === 'manual_charge' ? 'The approved deduction was recorded erroneously; its destination retains the full amount.' : 'Actual tender remains controlled by the original Agent.',
        'customer_explanation' => 'The erroneous original is corrected with its required linked entries.',
        'evidence_text' => 'Original amount, agreed terms and controlled destination verified.', 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($admin, $request);
    $test->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('reversals.approve', $request), ['attempt_reference' => (string) Str::uuid(),
            'version' => $request->version, 'preview_fingerprint' => $review['preview_fingerprint'],
            'decision_reason' => 'Reviewed original custody evidence.', 'confirmed' => true])->assertRedirect();

    return $request;
}
