<?php

namespace App\Console\Commands;

use App\Models\BankPayoutAttempt;
use App\Services\BankPayoutService;
use App\Services\FakePayoutProvider;
use App\Support\PayoutProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('payouts:fake-event {attempt : Attempt reference} {event : succeeded, failed, settled or returned}')]
#[Description('Send a correctly signed sandbox provider callback through the real callback path (local and testing only)')]
class FakePayoutEvent extends Command
{
    public function handle(BankPayoutService $payouts, PayoutProvider $provider): int
    {
        if (! app()->environment(['local', 'testing']) || ! $provider instanceof FakePayoutProvider) {
            $this->error('The fake payout provider is available only in local and testing environments.');

            return self::FAILURE;
        }
        $event = (string) $this->argument('event');
        if (! in_array($event, ['succeeded', 'failed', 'settled', 'returned'], true)) {
            $this->error('Unknown event. Use succeeded, failed, settled or returned.');

            return self::FAILURE;
        }
        $attempt = BankPayoutAttempt::query()->where('attempt_reference', (string) $this->argument('attempt'))->first();
        if ($attempt === null) {
            $this->error('Unknown attempt.');

            return self::FAILURE;
        }
        $signed = FakePayoutProvider::signedCallback(['event_id' => (string) Str::uuid(), 'event_type' => 'transfer.'.$event,
            'idempotency_key' => $attempt->idempotency_key, 'provider_reference' => $attempt->provider_reference,
            'amount_kobo' => $attempt->amount_kobo, 'return_reference' => $event === 'returned' ? (string) Str::uuid() : null,
            'occurred_at' => now()->toIso8601String()]);
        $callback = $provider->verifyCallback($signed['body'], $signed['headers']);
        $this->info('Callback '.$payouts->ingestCallback($callback).'.');

        return self::SUCCESS;
    }
}
