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
        Schema::table('customer_status_histories', function (Blueprint $table): void {
            $table->foreignId('audit_event_id')->nullable()->after('changed_by_user_id')->constrained('audit_events')->restrictOnDelete();
            $table->string('customer_facing_explanation', 500)->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_status_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('audit_event_id');
            $table->dropColumn('customer_facing_explanation');
        });
    }
};
