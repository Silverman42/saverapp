<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_cash_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 40);
            $table->uuid('operation_reference');
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_disbursement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fee_refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamps();
            $table->unique(['operation_reference', 'event_type']);
        });
        Schema::create('financial_cash_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('financial_cash_event_id')->constrained('financial_cash_events', indexName: 'cash_notice_event_fk')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 20);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['financial_cash_event_id', 'recipient_user_id'], 'financial_cash_notice_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial notification evidence requires forward migrations.');
    }
};
