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
        Schema::create('agent_offboarding_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->unsignedTinyInteger('is_open')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['agent_profile_id', 'is_open'], 'agent_offboarding_one_open_unique');
            $table->index(['agent_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_offboarding_cases');
    }
};
