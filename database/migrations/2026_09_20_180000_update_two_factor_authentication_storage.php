<?php

use App\Enums\AccountState;
use App\Enums\AuthenticatorState;
use App\Enums\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fail-fast check: legacy Fortify factor data cannot safely meet the hashed storage contract.
        if (DB::table('users')->whereNotNull('two_factor_secret')->orWhereNotNull('two_factor_recovery_codes')->exists()) {
            throw new RuntimeException('Cannot migrate two-factor authentication: legacy Fortify factor data found. Plaintext-recoverable codes cannot safely meet the new storage contract.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('authenticator_state')->default(AuthenticatorState::NotConfigured->value)->after('account_state');
            $table->unsignedBigInteger('two_factor_last_used_timestep')->nullable()->after('two_factor_confirmed_at');
            $table->text('two_factor_pending_secret')->nullable()->after('two_factor_last_used_timestep');
            $table->string('two_factor_pending_purpose')->nullable()->after('two_factor_pending_secret');
            $table->timestamp('two_factor_pending_expires_at')->nullable()->after('two_factor_pending_purpose');
            $table->unsignedBigInteger('two_factor_pending_last_used_timestep')->nullable()->after('two_factor_pending_expires_at');
            $table->timestamp('recovery_codes_acknowledged_at')->nullable()->after('two_factor_pending_last_used_timestep');
            $table->dropColumn('two_factor_recovery_codes');
        });

        Schema::create('two_factor_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64)->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        // Transition active Admins and Agents without MFA to mfa_setup_required; keep Customers password-only.
        DB::table('users')
            ->whereIn('user_type', [UserType::Admin->value, UserType::Agent->value])
            ->where('account_state', AccountState::Active->value)
            ->where(function ($query) {
                $query->whereNull('two_factor_secret')
                    ->orWhereNull('two_factor_confirmed_at');
            })
            ->update([
                'account_state' => AccountState::MfaSetupRequired->value,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->dropColumn([
                'authenticator_state',
                'two_factor_last_used_timestep',
                'two_factor_pending_secret',
                'two_factor_pending_purpose',
                'two_factor_pending_expires_at',
                'two_factor_pending_last_used_timestep',
                'recovery_codes_acknowledged_at',
            ]);
        });
    }
};
