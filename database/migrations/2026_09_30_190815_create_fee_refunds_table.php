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
        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('refund_reference')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('kind', 20);
            $table->text('reason');
            $table->foreignId('ledger_posting_group_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Refund history requires forward migrations.');
    }
};
