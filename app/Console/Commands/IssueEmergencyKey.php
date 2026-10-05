<?php

namespace App\Console\Commands;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use App\Services\EmergencyRecoveryService;
use Illuminate\Console\Command;

class IssueEmergencyKey extends Command
{
    protected $signature = 'business:emergency-key {--admin-email= : Seeded Administrator email the key is bound to} {--rotate : Replace an existing key}';

    protected $description = 'Issue the single-use offline business emergency recovery key and display it once';

    public function handle(EmergencyRecoveryService $service): int
    {
        if ($service->hasActiveKey() && ! $this->option('rotate')) {
            $this->error('An emergency key already exists. Use --rotate to replace it; the old key stops working immediately.');

            return self::FAILURE;
        }

        $admin = User::query()->where('user_type', UserType::Admin->value)
            ->whereIn('account_state', [AccountState::Active->value, AccountState::MfaSetupRequired->value])
            ->where('email_normalized', strtolower(trim((string) $this->option('admin-email'))))->first();
        if ($admin === null) {
            $this->error('Provide --admin-email for an activated Administrator.');

            return self::FAILURE;
        }

        $key = $service->issue($admin, $this->option('rotate') ? 'rotated_by_operator' : 'issued_by_operator');

        $this->warn('Store this key offline now. It is shown once and only its hash is kept:');
        $this->line($key);
        $this->info("Bound to {$admin->email}. Emergency recovery needs this key and access to that mailbox.");

        return self::SUCCESS;
    }
}
