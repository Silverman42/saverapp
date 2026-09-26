<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('family', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_version')->default(1);
            $table->string('event_type', 80);
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('facts');
            $table->string('timezone', 80);
            $table->string('operation_reference', 80)->nullable();
            $table->string('actor_category', 16);
            $table->unsignedBigInteger('audit_event_id')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('created_at');
            $table->unique(['family', 'source_id', 'source_version']);
        });
        Schema::create('notification_inbox_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('notification_events');
            $table->foreignId('recipient_user_id')->constrained('users');
            $table->uuid('notification_id')->unique();
            $table->string('channel', 16)->default('database');
            $table->json('audiences');
            $table->unsignedBigInteger('customer_profile_id')->nullable()->index();
            $table->unsignedBigInteger('agent_profile_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('action_correction_id')->nullable();
            $table->string('category', 32);
            $table->string('importance', 16)->default('normal');
            $table->boolean('mandatory')->default(true);
            $table->boolean('action_required')->default(false);
            $table->string('template_id', 80);
            $table->unsignedInteger('template_version')->default(1);
            $table->string('locale', 16);
            $table->string('title', 160);
            $table->text('summary');
            $table->string('reference', 80)->nullable();
            $table->json('destination')->nullable();
            $table->char('snapshot_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('failure_category', 60)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['event_id', 'recipient_user_id', 'channel'], 'notification_recipient_channel_unique');
            $table->index(['recipient_user_id', 'status', 'effective_at', 'notification_id'], 'notification_inbox_list_index');
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('notification_inbox_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intent_id')->constrained('notification_inbox_intents');
            $table->unsignedInteger('attempt_number');
            $table->string('outcome', 20);
            $table->string('failure_category', 60)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unique(['intent_id', 'attempt_number']);
        });
        Schema::create('notification_inbox_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intent_id')->constrained('notification_inbox_intents');
            $table->string('family', 32);
            $table->unsignedBigInteger('owner_intent_id');
            $table->uuid('notification_id');
            $table->unique(['family', 'owner_intent_id']);
            $table->index('notification_id');
        });
        Schema::table('customer_name_corrections', function (Blueprint $table): void {
            $table->unsignedBigInteger('profile_change_history_id')->nullable()->unique();
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->unsignedInteger('read_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn('read_version'));
        Schema::table('customer_name_corrections', fn (Blueprint $table) => $table->dropColumn('profile_change_history_id'));
        Schema::dropIfExists('notification_inbox_aliases');
        Schema::dropIfExists('notification_inbox_attempts');
        Schema::dropIfExists('notification_inbox_intents');
        Schema::dropIfExists('notification_events');
    }
};
