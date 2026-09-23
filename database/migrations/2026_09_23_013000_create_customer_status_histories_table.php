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
        Schema::create('customer_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->string('reason', 500);
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_profile_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_status_histories');
    }
};
