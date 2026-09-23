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
        Schema::create('customer_status_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('customer_status_history_id')->constrained('customer_status_histories', indexName: 'customer_status_intent_history_fk')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 30);
            $table->string('channel', 20);
            $table->string('purpose', 60);
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();

            $table->unique([
                'customer_status_history_id',
                'recipient_user_id',
                'channel',
                'purpose',
            ], 'customer_status_intent_unique');
            $table->index(['customer_profile_id', 'created_at'], 'customer_status_intent_profile_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_status_notification_intents');
    }
};
