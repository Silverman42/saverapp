<?php

use App\Models\ChargeCategoryVersion;

/** @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function reviewManualCharge(object $test, array $payload): array
{
    $category = ChargeCategoryVersion::query()->findOrFail($payload['category_id']);
    $payload['mode'] ??= $category->kind === 'manual_fee' ? 'assessment_only' : 'deduction';
    $inputs = array_intersect_key($payload, array_flip(['customer_id', 'plan_id', 'category_id', 'customer_version', 'plan_version', 'reason', 'mode']));
    $quote = $test->postJson(route('admin.charges.preview'), $inputs)->assertOk()->json();

    return [...$payload, 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
}
