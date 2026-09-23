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
        Schema::table('agent_status_histories', function (Blueprint $table): void {
            $table->string('reason', 500)->change();
            $table->string('agent_facing_explanation', 500)->nullable();
            $table->foreignId('audit_event_id')->nullable()->constrained('audit_events')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_status_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('audit_event_id');
            $table->dropColumn('agent_facing_explanation');
            $table->string('reason')->change();
        });
    }
};
