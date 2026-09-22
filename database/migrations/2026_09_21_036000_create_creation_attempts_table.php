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
        Schema::create('creation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('attempt_reference', 100)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('business_id', 30)->index();
            $table->string('operation_type', 50)->index();
            $table->string('payload_fingerprint', 64)->index();
            $table->string('status', 30)->default('in_progress')->index();
            $table->string('record_type')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'operation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creation_attempts');
    }
};
