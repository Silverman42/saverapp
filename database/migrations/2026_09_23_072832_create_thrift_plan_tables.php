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
        Schema::create('thrift_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_id', 32)->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('predecessor_plan_id')->nullable()->unique()->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('open_customer_profile_id')->nullable()->unique()->constrained('customer_profiles')->restrictOnDelete();
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('current_terms_revision');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('activity_started_at')->nullable();
            $table->timestamps();

            $table->index(['customer_profile_id', 'status', 'created_at']);
        });

        Schema::create('plan_operation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('business_id', 30);
            $table->string('operation_type', 40);
            $table->char('payload_fingerprint', 64);
            $table->string('status', 20)->default('in_progress');
            $table->foreignId('thrift_plan_id')->nullable()->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('result_summary')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'operation_type', 'created_at']);
        });

        Schema::create('plan_terms_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->string('name', 100);
            $table->unsignedBigInteger('contribution_amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->date('start_date');
            $table->unsignedSmallInteger('contribution_days');
            $table->string('frequency', 20)->default('daily');
            $table->string('timezone', 64);
            $table->unsignedInteger('business_version');
            $table->unsignedBigInteger('expected_gross_kobo');
            $table->foreignId('fee_snapshot_id')->constrained('fee_snapshots')->restrictOnDelete();
            $table->text('customer_visible_notes')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('attested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('attested_at');
            $table->timestamps();

            $table->unique(['thrift_plan_id', 'revision']);
            $table->index(['start_date', 'timezone']);
        });

        Schema::create('contribution_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('plan_terms_revision_id')->constrained('plan_terms_revisions')->restrictOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->date('due_date');
            $table->unsignedBigInteger('expected_amount_kobo');
            $table->unsignedSmallInteger('active_ordinal')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['thrift_plan_id', 'active_ordinal']);
            $table->unique(['plan_terms_revision_id', 'ordinal']);
            $table->index(['thrift_plan_id', 'due_date', 'superseded_at']);
        });

        Schema::create('plan_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('plan_operation_attempt_id')->nullable()->constrained('plan_operation_attempts')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('assignment_version')->nullable();
            $table->unsignedInteger('plan_version');
            $table->string('reason', 500)->nullable();
            $table->string('customer_explanation', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['thrift_plan_id', 'effective_at', 'id']);
        });

        Schema::create('plan_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('plan_lifecycle_event_id')->constrained('plan_lifecycle_events')->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 20);
            $table->string('purpose', 40);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['plan_lifecycle_event_id', 'recipient_user_id', 'channel'], 'plan_notification_intent_dedup');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_notification_intents');
        Schema::dropIfExists('plan_lifecycle_events');
        Schema::dropIfExists('contribution_slots');
        Schema::dropIfExists('plan_terms_revisions');
        Schema::dropIfExists('plan_operation_attempts');
        Schema::dropIfExists('thrift_plans');
    }
};
