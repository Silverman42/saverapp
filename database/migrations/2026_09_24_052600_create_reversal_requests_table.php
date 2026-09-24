<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reversal_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reversal_id')->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_posting_group_id')->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('live_original_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('posted_original_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('compensation_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiating_agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('reason_category', 40);
            $table->string('internal_reason', 1000);
            $table->string('customer_explanation', 500);
            $table->string('evidence_text', 1000);
            $table->string('decision_reason', 500)->nullable();
            $table->char('dependency_fingerprint', 64);
            $table->json('dependency_snapshot');
            $table->unsignedBigInteger('original_amount_kobo');
            $table->string('currency', 3);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('live_original_posting_group_id', 'reversal_one_pending');
            $table->unique('posted_original_posting_group_id', 'reversal_one_posted');
            $table->index(['customer_profile_id', 'created_at', 'id']);
            $table->index(['state', 'created_at', 'id']);
        });

        Schema::create('reversal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('reversal_request_id')->nullable()->constrained('reversal_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 20);
            $table->char('payload_hash', 64);
            $table->timestamps();
        });

        Schema::create('reversal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_request_id')->constrained('reversal_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('customer_explanation', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('effective_at');
            $table->index(['reversal_request_id', 'effective_at', 'id']);
        });

        Schema::create('reversal_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('reversal_event_id')->constrained('reversal_events')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 16);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['reversal_event_id', 'recipient_user_id', 'channel'], 'reversal_notice_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('reversal_requests')->exists()) {
            throw new RuntimeException('Reversal evidence cannot be discarded by rolling back this migration.');
        }

        Schema::dropIfExists('reversal_notification_intents');
        Schema::dropIfExists('reversal_events');
        Schema::dropIfExists('reversal_attempts');
        Schema::dropIfExists('reversal_requests');
    }
};
