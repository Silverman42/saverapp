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
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->index(['customer_profile_id', 'effective_at', 'id', 'ended_at', 'agent_profile_id'], 'customer_assignments_dated_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->dropIndex('customer_assignments_dated_lookup_idx');
        });
    }
};
