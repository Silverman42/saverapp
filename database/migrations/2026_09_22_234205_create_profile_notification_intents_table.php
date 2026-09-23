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
        Schema::create('profile_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('profile_change_history_id')->constrained('profile_change_histories')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('audience_type', 40);
            $table->string('channel', 20);
            $table->string('purpose', 60);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_change_history_id', 'recipient_user_id', 'channel', 'purpose'], 'profile_notification_intent_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_notification_intents');
    }
};
