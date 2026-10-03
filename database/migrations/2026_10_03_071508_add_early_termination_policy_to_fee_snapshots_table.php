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
        Schema::table('fee_snapshots', function (Blueprint $table): void {
            $table->unsignedSmallInteger('early_termination_policy_version')->nullable();
            $table->text('early_termination_description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fee_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['early_termination_policy_version', 'early_termination_description']);
        });
    }
};
