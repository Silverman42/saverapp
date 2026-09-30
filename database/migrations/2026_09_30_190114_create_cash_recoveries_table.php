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
        Schema::create('cash_recoveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('recovery_reference')->unique();
            $table->foreignId('cash_execution_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('custodian_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->text('evidence');
            $table->char('payload_hash', 64);
            $table->string('status', 30);
            $table->text('customer_acknowledgement')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('ledger_posting_group_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Cash evidence requires forward migrations.');
    }
};
