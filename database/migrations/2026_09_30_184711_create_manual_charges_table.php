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
        Schema::create('manual_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_reference')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('charge_category_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->foreignId('fee_obligation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ledger_posting_group_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('manual_charge_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('manual_charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 20);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['manual_charge_id', 'recipient_user_id'], 'manual_charge_notice_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Financial charges require forward migrations.');
    }
};
