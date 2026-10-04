<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approved postings retain their independent approver on the immutable group. Historical groups stay unchanged and null.
     */
    public function up(): void
    {
        Schema::table('ledger_posting_groups', function (Blueprint $table): void {
            $table->foreignId('approver_user_id')->nullable()->after('actor_user_id')->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_posting_groups')->whereNotNull('approver_user_id')->exists()) {
            throw new RuntimeException('Recorded ledger approvers cannot be removed by rollback.');
        }
        Schema::table('ledger_posting_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approver_user_id');
        });
    }
};
