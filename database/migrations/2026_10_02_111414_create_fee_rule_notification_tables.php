<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rule_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event_type', 20);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->timestamp('created_at');
            $table->unique(['fee_rule_id', 'event_type']);
        });
        Schema::create('fee_rule_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_rule_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('notification_id')->unique();
            $table->string('audience_type', 30)->default('fee_manager');
            $table->string('channel', 16)->default('database');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['fee_rule_event_id', 'recipient_user_id', 'channel'], 'fee_rule_notice_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rule_notification_intents');
        Schema::dropIfExists('fee_rule_events');
    }
};
