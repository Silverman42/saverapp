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
        Schema::table('ledger_transaction_projections', function (Blueprint $table): void {
            $table->unsignedBigInteger('compensating_reference_id')->nullable()->after('status');
            $table->foreign('compensating_reference_id', 'ledger_tx_compensation_fk')
                ->references('id')->on('ledger_transaction_references')->restrictOnDelete();
        });
        Schema::table('ledger_integrity_incidents', function (Blueprint $table): void {
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Integrity incident history requires a forward migration.');
    }
};
