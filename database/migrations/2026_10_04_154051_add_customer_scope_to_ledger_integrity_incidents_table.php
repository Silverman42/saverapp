<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scoped incident freezes only one Customer's derived reads; a null scope keeps the global freeze.
     */
    public function up(): void
    {
        Schema::table('ledger_integrity_incidents', function (Blueprint $table): void {
            $table->foreignId('customer_profile_id')->nullable()->after('category')->constrained()->restrictOnDelete();
            $table->index(['customer_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_integrity_incidents')->whereNotNull('customer_profile_id')->exists()) {
            throw new RuntimeException('Scoped ledger integrity incident evidence cannot be removed by rollback.');
        }
        Schema::table('ledger_integrity_incidents', function (Blueprint $table): void {
            $table->dropIndex(['customer_profile_id', 'status']);
            $table->dropConstrainedForeignId('customer_profile_id');
        });
    }
};
