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
        foreach (['collection_evidence_files', 'collection_settlement_files', 'reversal_evidence_files'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['scanner_version', 'scanned_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Evidence file history requires a forward migration.');
    }
};
