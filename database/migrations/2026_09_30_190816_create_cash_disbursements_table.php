<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_disbursements', function (Blueprint $table) {
            $table->id();
            $table->uuid('execution_reference')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('fee_refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('live_fee_refund_id')->nullable()->unique()->constrained('fee_refunds')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('executor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 30);
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedInteger('cash_mapping_version');
            $table->unsignedInteger('debit_mapping_version');
            $table->unsignedInteger('method_version');
            $table->string('status', 30);
            $table->text('custody_evidence');
            $table->text('handoff_evidence')->nullable();
            $table->text('acknowledgement')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('ledger_posting_group_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Cash execution evidence requires forward migrations.');
    }
};
