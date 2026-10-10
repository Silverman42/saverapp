<?php

use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Services\AuditCapture;
use App\Services\AuditProjection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['audit.enabled' => true]);
    Queue::fake([ProjectAuditEvent::class]);
});

test('AUD-AC-006: credentials, card data, key material and error dumps in allowed field values never persist', function (string $event, array $payload): void {
    expect(fn () => AuditEvent::record($event, 'customer', 1, 'CUS-000001', $payload))->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('audit_events', 0);
    $this->assertDatabaseCount('canonical_audit_events', 0);
    $this->assertDatabaseCount('audit_protected_payloads', 0);
})->with([
    'card number in protected reason' => ['customer.status_changed', ['to_status' => 'restricted', 'reason' => 'Customer read out card 4111 1111 1111 1111 on the call']],
    'dashed card number' => ['customer.status_changed', ['reason' => 'Card 5500-0000-0000-0004 was shown']],
    'bearer token' => ['withdrawal.bank_failed', ['failure_code' => 'Bearer abcDEF123456789._-ghiJKLmno']],
    'JSON web token' => ['withdrawal.bank_failed', ['provider_outcome' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJl']],
    'private key' => ['withdrawal.bank_failed', ['provider_outcome' => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAK"]],
    'stack trace' => ['withdrawal.bank_failed', ['failure_code' => "Error\nStack trace:\n#0 /var/www/app/Services/Bank.php(42)"]],
    'SQL error dump' => ['withdrawal.bank_failed', ['provider_outcome' => 'SQLSTATE[42S02]: Base table or view not found']],
    'raw file' => ['customer.status_changed', ['reason' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg']],
    'nested before value' => ['customer.profile_updated', ['before_values' => ['address' => 'Card 4111111111111111']]],
]);

test('AUD-AC-006: opaque provider references and ordinary numbers remain recordable', function (): void {
    $event = AuditEvent::record('withdrawal.bank_posted', 'withdrawal', 1, 'WDL-000001', [
        'provider_reference' => '4111111111111111', 'execution_reference' => '5500000000000004',
        'amount_kobo' => 4111111111111111, 'state' => 'posted', 'failure_code' => 'Phone +2348012345670 was busy',
    ]);
    app(AuditProjection::class)->drain();

    expect($event->payload['provider_reference'])->toBe('4111111111111111')
        ->and(DB::table('canonical_audit_events')->count())->toBe(1);
});

test('AUD-AC-006: a historical import drops secret material instead of copying it', function (): void {
    $legacy = AuditEvent::query()->create(['event_type' => 'customer.status_changed', 'target_type' => 'customer', 'target_id' => 1,
        'payload' => ['to_status' => 'restricted', 'reason' => 'Card 4111111111111111', 'operational_status' => 'Bearer abcDEF123456789._-ghiJKLmno'],
        'created_at' => now()]);

    expect(app(AuditCapture::class)->import($legacy))->toBeTrue();

    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $legacy->id)->sole();
    expect($canonical->content)->not->toContain('4111111111111111')->not->toContain('Bearer')
        ->and(DB::table('audit_protected_payloads')->where('canonical_event_id', $canonical->id)->get()
            ->map(fn (object $row): string => Crypt::decryptString($row->ciphertext))->implode(''))->not->toContain('4111');
});

test('AUD-AC-006: the same events record when their values carry no secret material', function (string $event, array $payload): void {
    AuditEvent::record($event, 'customer', 1, 'CUS-000001', $payload);

    $this->assertDatabaseCount('canonical_audit_events', 1);
})->with([
    'protected reason' => ['customer.status_changed', ['to_status' => 'restricted', 'reason' => 'Customer asked for a review on 2026-09-16']],
    'failure code' => ['withdrawal.bank_failed', ['failure_code' => 'insufficient_funds']],
    'provider outcome' => ['withdrawal.bank_failed', ['provider_outcome' => 'declined']],
    'nested before value' => ['customer.profile_updated', ['before_values' => ['address' => '12 Allen Avenue, Ikeja']]],
    'E.164 phone passing Luhn' => ['agent.registered', ['phone_normalized' => '+2348011223303', 'account_state' => 'invited']],
    'E.164 phone in a protected change' => ['customer.profile_updated', ['before_values' => ['phone' => '+2348011223303']]],
]);
