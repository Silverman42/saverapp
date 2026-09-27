<?php

namespace App\Console\Commands;

use App\Jobs\DeliverCustomerHandoverNotice;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Services\CustomerRecoveryService;
use App\Services\NotificationPipeline;
use App\Services\PlatformGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireCustomerRecovery extends Command
{
    protected $signature = 'customers:expire-recovery';

    protected $description = 'Expire Customer recovery challenges and drain durable handover notices';

    public function handle(CustomerRecoveryService $recovery, PlatformGuard $platform, NotificationPipeline $pipeline): int
    {
        $customerIds = CustomerRecovery::query()->whereNotNull('open_customer_id')
            ->where(fn ($q) => $q->where(fn ($pending) => $pending->whereIn('state', ['awaiting_approval', 'verification_required'])->where('request_expires_at', '<=', now()))
                ->orWhere(fn ($approved) => $approved->where('state', 'awaiting_activation')->where('activation_expires_at', '<=', now())))
            ->distinct()->pluck('customer_profile_id');
        foreach ($customerIds as $id) {
            $platform->transaction('mutation', function () use ($id, $recovery): void {
                $customer = CustomerProfile::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $recovery->expireForCustomer($customer);
            }, attempts: 3);
        }
        DB::table('customer_handover_notices')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'failure_reason' => 'Delivery interrupted; provider acceptance is unknown.', 'updated_at' => now()]);
        foreach (DB::table('customer_handover_notices')->where('status', 'pending')->orderBy('id')->limit(100)->pluck('id') as $id) {
            $pipeline->dispatchRecoverably(static fn () => DeliverCustomerHandoverNotice::dispatch((int) $id));
        }

        return self::SUCCESS;
    }
}
