<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('recovery_pending')->default(false);
        });
        Schema::create('email_identity_locks', function (Blueprint $table): void {
            $table->string('normalized_email')->primary();
        });
        Schema::create('customer_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('open_customer_id')->nullable()->unique();
            $table->string('state', 40);
            $table->unsignedInteger('version')->default(1);
            $table->text('previous_email');
            $table->text('proposed_email');
            $table->string('proposed_email_normalized')->nullable()->unique();
            $table->string('activation_token_hash', 64)->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('request_expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'request_expires_at']);
        });
        Schema::create('customer_handover_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_recovery_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
            $table->string('event_type');
            $table->unsignedInteger('to_version');
            $table->text('details');
            $table->timestamp('effective_at');
        });
        Schema::create('customer_handover_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->string('action', 40);
            $table->string('payload_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
        });
        Schema::create('customer_handover_notices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('customer_handover_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('audience_type', 40);
            $table->string('purpose', 40);
            $table->string('channel', 20);
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['customer_handover_event_id', 'recipient_user_id', 'channel', 'purpose'], 'handover_notice_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_handover_notices');
        Schema::dropIfExists('customer_handover_operations');
        Schema::dropIfExists('customer_handover_events');
        Schema::dropIfExists('customer_recoveries');
        Schema::dropIfExists('email_identity_locks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('recovery_pending'));
    }
};
