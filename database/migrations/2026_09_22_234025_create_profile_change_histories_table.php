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
        Schema::create('profile_change_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->string('event_type', 100)->index();
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 50)->nullable();
            $table->unsignedBigInteger('audit_event_id')->nullable()->index();
            $table->json('changed_fields');
            $table->text('before_values')->nullable();
            $table->text('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('from_version')->nullable();
            $table->unsignedInteger('to_version')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['target_type', 'target_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_change_histories');
    }
};
