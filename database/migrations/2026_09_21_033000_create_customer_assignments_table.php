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
        Schema::create('customer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')
                ->constrained('customer_profiles')
                ->restrictOnDelete();
            $table->foreignId('agent_profile_id')
                ->constrained('agent_profiles')
                ->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 30)->default('current');
            $table->unsignedTinyInteger('is_current')->nullable()->default(1);
            $table->timestamp('effective_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();

            // At most one current assignment per customer (using 1 for current, NULL for historical)
            $table->unique(['customer_profile_id', 'is_current'], 'customer_assignments_one_current_unique');

            // Monotonic unique assignment version per customer
            $table->unique(['customer_profile_id', 'version'], 'customer_assignments_version_unique');

            // Fast lookup of current assignments for an agent
            $table->index(['agent_profile_id', 'is_current'], 'customer_assignments_agent_current_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_assignments');
    }
};
