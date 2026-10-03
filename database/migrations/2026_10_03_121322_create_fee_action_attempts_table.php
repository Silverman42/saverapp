<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_action_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
            $table->string('operation', 30);
            $table->char('payload_hash', 64)->nullable();
            $table->string('status', 20);
            $table->string('source_type', 50)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason_code', 50)->nullable();
            $table->foreignId('cancellation_audit_event_id')->nullable()->constrained('audit_events')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_action_attempts');
    }
};
