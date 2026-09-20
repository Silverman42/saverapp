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
        if (Schema::hasTable('authentication_locks')) {
            return;
        }

        Schema::create('authentication_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email_normalized')->index();
            $table->string('lock_category')->index(); // 'password', 'mfa', 'recovery_code'
            $table->string('reason');
            $table->unsignedInteger('failed_attempts_count')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('locked_at');
            $table->timestamp('locked_until')->index();
            $table->boolean('requires_review')->default(false)->index();
            $table->timestamp('unlocked_at')->nullable()->index();
            $table->foreignId('unlocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unlock_reason')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'lock_category', 'locked_until']);
            $table->index(['email_normalized', 'lock_category', 'locked_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authentication_locks');
    }
};
