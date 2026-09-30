<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('execution_reference')->unique();
            $table->foreignId('withdrawal_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('live_withdrawal_request_id')->nullable()->unique()->constrained('withdrawal_requests')->restrictOnDelete();
            $table->foreignId('executor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedInteger('method_version');
            $table->unsignedInteger('cash_mapping_version');
            $table->string('status', 30);
            $table->string('start_payload_hash', 64);
            $table->text('custody_evidence');
            $table->text('handoff_evidence')->nullable();
            $table->text('customer_acknowledgement')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('ledger_posting_group_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('ledger_accounts')->insert([
            ['code' => 'unapplied_funds_ngn', 'account_class' => 'unapplied_funds', 'normal_balance' => 'credit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'version' => 1, 'display_name' => 'Controlled unapplied funds', 'purpose' => 'Received funds pending an authorized allocation or return.', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'business_distributions_ngn', 'account_class' => 'business_distributions', 'normal_balance' => 'debit', 'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'version' => 1, 'display_name' => 'Business earnings distributions', 'purpose' => 'Evidenced cash-backed business earnings draws.', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Cash execution evidence requires forward migrations.');
    }
};
