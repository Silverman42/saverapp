<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_operational_issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('issue_reference')->unique();
            $table->string('issue_kind', 30);
            $table->string('event_type', 30);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_identity', 120);
            $table->string('operation_reference', 120);
            $table->char('state_fingerprint', 64);
            $table->string('source_family', 30)->nullable();
            $table->unsignedBigInteger('source_owner_id')->nullable();
            $table->foreignId('fee_obligation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fee_snapshot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
            $table->string('state', 40);
            $table->string('category', 60);
            $table->text('context_ciphertext');
            $table->string('timezone', 64);
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->unique(['source_identity', 'state_fingerprint'], 'fee_issue_state_once');
        });
        Schema::create('fee_issue_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('fee_operational_issue_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->string('audience_type', 40);
            $table->string('channel', 16)->default('database');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['fee_operational_issue_id', 'recipient_user_id', 'channel'], 'fee_issue_notice_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retained fee operational evidence requires forward migrations.');
    }
};
