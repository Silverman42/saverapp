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
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_email', 254);
            $table->string('target_email_normalized', 254)->index();
            $table->string('role', 30)->index();
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('generation')->default(1);
            $table->string('status', 30)->default('pending_delivery')->index();
            $table->string('delivery_status', 30)->default('pending')->index();
            $table->text('delivery_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('expires_at')->index();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
