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
        Schema::create('audit_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('audit_event_id')->constrained('audit_events', indexName: 'audit_notice_event_fk')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users', indexName: 'audit_notice_recipient_fk')->restrictOnDelete();
            $table->string('family', 40);
            $table->string('audience_type', 40);
            $table->string('channel', 20)->default('database');
            $table->string('status', 20)->default('pending')->index('audit_notice_status_index');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();

            $table->unique(['audit_event_id', 'recipient_user_id'], 'audit_notice_recipient_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_notification_intents');
    }
};
