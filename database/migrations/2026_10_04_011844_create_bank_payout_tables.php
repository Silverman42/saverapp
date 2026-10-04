<?php

use App\Services\AuditProjection;
use App\Services\BankMethodCatalogue;
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
        Schema::create('bank_payout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('withdrawal_request_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->unique(['withdrawal_request_id', 'attempt_number']);
            $table->foreignId('live_withdrawal_request_id')->nullable()->unique()->constrained('withdrawal_requests')->restrictOnDelete();
            $table->foreignId('executor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_payout_destination_id')->constrained('customer_payout_destinations')->restrictOnDelete();
            $table->unsignedInteger('destination_version');
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3);
            $table->unsignedInteger('method_version');
            $table->unsignedInteger('payout_mapping_version');
            $table->unsignedInteger('funding_mapping_version');
            $table->string('provider_key', 32);
            $table->char('idempotency_key', 64)->unique();
            $table->char('start_payload_hash', 64);
            $table->string('status', 16);
            $table->string('provider_outcome', 16)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->unique(['provider_key', 'provider_reference']);
            $table->text('provider_evidence')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->unsignedSmallInteger('dispatch_count')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('provider_occurred_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('ledger_posting_group_id')->nullable()->unique()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('settlement_posting_group_id')->nullable()->unique()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_check_at']);
        });
        Schema::create('bank_payout_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 32);
            $table->string('event_id', 100);
            $table->unique(['provider_key', 'event_id']);
            $table->string('event_type', 40);
            $table->char('idempotency_key', 64)->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('signature_timestamp');
            $table->foreignId('bank_payout_attempt_id')->nullable()->constrained('bank_payout_attempts')->restrictOnDelete();
            $table->string('disposition', 16);
            $table->text('sanitized_payload');
            $table->timestamp('received_at');
        });
        Schema::create('bank_payout_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('return_reference')->unique();
            $table->foreignId('bank_payout_attempt_id')->constrained('bank_payout_attempts')->restrictOnDelete();
            $table->string('provider_key', 32);
            $table->string('provider_return_reference', 100);
            $table->unique(['provider_key', 'provider_return_reference'], 'bank_payout_returns_provider_ref_unique');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 16);
            $table->text('evidence');
            $table->char('payload_hash', 64);
            $table->foreignId('return_posting_group_id')->nullable()->unique()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('ledger_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index('bank_payout_attempt_id');
        });
        DB::table('ledger_accounts')->insertOrIgnore([
            'code' => 'payout_clearing_ngn', 'account_class' => 'payout_clearing', 'normal_balance' => 'credit',
            'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'version' => 1, 'display_name' => 'Bank payout clearing',
            'purpose' => 'Bank transfers sent to Customers and awaiting provider settlement against business bank funding.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cash_method_versions')->insert(['method_key' => BankMethodCatalogue::METHOD_KEY, 'version' => 1,
            'contract' => json_encode(BankMethodCatalogue::VERSION_ONE, JSON_THROW_ON_ERROR),
            'contract_hash' => AuditProjection::digest(BankMethodCatalogue::VERSION_ONE),
            'effective_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Bank payout evidence requires a forward migration.');
    }
};
