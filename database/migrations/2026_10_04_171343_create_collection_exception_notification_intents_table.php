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
        Schema::create('collection_exception_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('collection_exception_event_id')->constrained('collection_exception_events', indexName: 'collection_exception_notice_event_fk')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users', indexName: 'collection_exception_notice_recipient_fk')->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained('agent_profiles', indexName: 'collection_exception_notice_agent_fk')->restrictOnDelete();
            $table->string('audience_type', 40);
            $table->string('channel', 20)->default('database');
            $table->string('status', 20)->default('pending')->index('collection_exception_notice_status_index');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();

            $table->unique(['collection_exception_event_id', 'recipient_user_id'], 'collection_exception_notice_recipient_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_exception_notification_intents');
    }
};
