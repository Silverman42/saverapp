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
        Schema::create('pending_email_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('current_email');
            $table->text('proposed_email');
            $table->string('proposed_email_normalized', 255)->unique();
            $table->string('current_token_hash', 64);
            $table->string('proposed_token_hash', 64);
            $table->timestamp('current_confirmed_at')->nullable();
            $table->timestamp('proposed_confirmed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_email_changes');
    }
};
