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
        Schema::create('fee_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('fee_snapshot_id')->constrained('fee_snapshots')->restrictOnDelete();
            $table->string('kind', 50)->default('registration');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 50)->default('pending');
            $table->string('due_condition', 100)->default('upon_registration');
            $table->string('customer_description', 500);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['customer_profile_id', 'kind']);
            $table->index(['customer_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_obligations');
    }
};
