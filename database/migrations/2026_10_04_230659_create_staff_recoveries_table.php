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
        Schema::create('staff_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('open_user_id')->nullable()->unique();
            $table->string('state', 40);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('required_approvals');
            $table->text('previous_email');
            $table->text('proposed_email');
            $table->string('proposed_email_normalized')->nullable()->unique();
            $table->string('procedure_reference', 150);
            $table->text('verification_notes');
            $table->timestamp('verified_at');
            $table->string('activation_token_hash', 64)->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('request_expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'request_expires_at']);
        });

        Schema::create('staff_recovery_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_recovery_id')->constrained()->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['staff_recovery_id', 'approver_user_id'], 'staff_recovery_approvals_unique_approver');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_recovery_approvals');
        Schema::dropIfExists('staff_recoveries');
    }
};
