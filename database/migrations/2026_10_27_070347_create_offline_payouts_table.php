<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->unique()->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('external_reference', 120)->nullable();
            $table->date('paid_on');
            $table->text('note');
            $table->unsignedBigInteger('amount_kobo');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('attempt_reference')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('ledger_posting_group_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Offline payout evidence requires forward migrations.');
    }
};
