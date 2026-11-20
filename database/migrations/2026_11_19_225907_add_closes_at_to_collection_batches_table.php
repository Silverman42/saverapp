<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moment a batch stops taking collections and becomes ready for remittance and review, captured when it is created.
     * Null for batches created before this column existed; those close at the end of their received date.
     */
    public function up(): void
    {
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->timestamp('closes_at')->nullable()->after('frozen_at');
            $table->index(['status', 'closes_at']);
        });
    }

    public function down(): void
    {
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->dropIndex(['status', 'closes_at']);
            $table->dropColumn('closes_at');
        });
    }
};
