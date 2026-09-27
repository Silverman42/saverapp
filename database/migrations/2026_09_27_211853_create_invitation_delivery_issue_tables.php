<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_family', 30);
            $table->unsignedBigInteger('owner_id');
            $table->string('outcome', 30);
            $table->string('transport_kind', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unique(['owner_family', 'owner_id'], 'management_delivery_owner_unique');
        });
        Schema::create('invitation_delivery_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
            $table->string('category', 40);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('invitation_issue_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('invitation_delivery_issue_id');
            $table->foreign('invitation_delivery_issue_id', 'invitation_issue_source_fk')->references('id')->on('invitation_delivery_issues')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable();
            $table->foreign('customer_profile_id', 'invitation_issue_customer_profile_id_fk')->references('id')->on('customer_profiles')->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable();
            $table->foreign('agent_profile_id', 'invitation_issue_agent_profile_id_fk')->references('id')->on('agent_profiles')->restrictOnDelete();
            $table->string('audience_type', 30);
            $table->string('channel', 16)->default('database');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['invitation_delivery_issue_id', 'recipient_user_id'], 'invitation_issue_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_issue_notification_intents');
        Schema::dropIfExists('invitation_delivery_issues');
        Schema::dropIfExists('management_delivery_attempts');
    }
};
