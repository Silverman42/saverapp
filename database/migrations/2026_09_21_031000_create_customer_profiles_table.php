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
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('customer_id', 32)->unique();
            $table->string('phone', 50);
            $table->string('phone_normalized', 50)->unique();
            $table->text('address')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('internal_reference', 50)->nullable();
            $table->string('internal_reference_normalized', 50)->nullable()->unique();
            $table->json('next_of_kin')->nullable();
            $table->string('operational_status', 30)->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
