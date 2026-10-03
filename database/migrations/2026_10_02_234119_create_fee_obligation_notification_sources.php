<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_obligation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_obligation_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('audit_event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('operation_reference')->unique();
            $table->string('event_type', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('entry_type', 40);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3);
            $table->unsignedBigInteger('outstanding_before_kobo');
            $table->unsignedBigInteger('outstanding_after_kobo');
            $table->text('customer_description');
            $table->string('timezone', 64);
            $table->timestamp('effective_at');
            $table->timestamps();
        });
        Schema::create('fee_obligation_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('fee_obligation_event_id')->constrained(indexName: 'fee_obligation_notice_event_fk')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 20);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['fee_obligation_event_id', 'recipient_user_id', 'channel'], 'fee_obligation_notice_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retained fee obligation notices require forward migrations.');
    }
};
