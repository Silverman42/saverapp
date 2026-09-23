<?php

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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('account_class', 50);
            $table->string('normal_balance', 10);
            $table->string('currency', 3)->default('NGN');
            $table->string('mapping_status', 20)->default('unconfigured');
            $table->timestamps();

            $table->index(['account_class', 'currency', 'mapping_status']);
        });

        DB::table('ledger_accounts')->insert([
            ['code' => 'business_cash_ngn', 'account_class' => 'asset', 'normal_balance' => 'debit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'agent_receivable_ngn', 'account_class' => 'agent_receivable', 'normal_balance' => 'debit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'customer_savings_liability_ngn', 'account_class' => 'customer_savings_liability', 'normal_balance' => 'credit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'fee_income_ngn', 'account_class' => 'fee_income', 'normal_balance' => 'credit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'refund_payable_ngn', 'account_class' => 'refund_payable', 'normal_balance' => 'credit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'other_deduction_destination_ngn', 'account_class' => 'other_deduction_destination', 'normal_balance' => 'credit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('ledger_posting_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('posting_reference', 100)->unique();
            $table->string('idempotency_key', 120)->unique();
            $table->string('payload_hash', 64);
            $table->string('source_type', 50);
            $table->string('source_id', 100);
            $table->string('event_type', 100);
            $table->string('currency', 3)->default('NGN');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('committed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['event_type', 'committed_at']);
            $table->index(['customer_profile_id', 'committed_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_posting_group_id')->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('side', 10);
            $table->unsignedBigInteger('amount_kobo');
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fee_obligation_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['ledger_posting_group_id', 'line_number']);
            $table->index(['ledger_account_id', 'customer_profile_id']);
            $table->index(['fee_obligation_id', 'ledger_posting_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_posting_groups')->exists() || DB::table('ledger_entries')->exists()) {
            throw new RuntimeException('Committed ledger evidence is immutable and cannot be removed by rolling back this migration.');
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_posting_groups');
        Schema::dropIfExists('ledger_accounts');
    }
};
