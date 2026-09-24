<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_transaction_references', function (Blueprint $table): void {
            $table->id();
            $table->string('root_type', 40);
            $table->string('root_id', 100);
            $table->string('transaction_reference', 40)->unique();
            $table->timestamps();
            $table->unique(['root_type', 'root_id']);
        });

        Schema::create('ledger_transaction_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_transaction_reference_id');
            $table->foreign('ledger_transaction_reference_id', 'ledger_tx_reference_fk')
                ->references('id')->on('ledger_transaction_references')->restrictOnDelete();
            $table->unsignedInteger('projection_version');
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('status', 20);
            $table->date('occurred_on');
            $table->timestamp('committed_at');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_amount_kobo');
            $table->bigInteger('savings_effect_kobo');
            $table->unsignedBigInteger('fee_amount_kobo');
            $table->unsignedInteger('posting_group_count');
            $table->unsignedBigInteger('source_max_group_id');
            $table->char('source_hash', 64);
            $table->timestamps();
            $table->unique(['ledger_transaction_reference_id', 'projection_version'], 'ledger_transaction_version_once');
            $table->index(['projection_version', 'customer_profile_id', 'committed_at', 'id'], 'ledger_transaction_customer_order');
            $table->index(['projection_version', 'type', 'occurred_on', 'id'], 'ledger_transaction_type_date');
        });

        Schema::create('ledger_projection_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('active_version');
            $table->unsignedBigInteger('ledger_group_watermark');
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();
        });

        DB::table('ledger_projection_state')->insert([
            'id' => 1, 'active_version' => 1, 'ledger_group_watermark' => 0,
            'verified_at' => null, 'status' => 'unavailable',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('public_id_sequences')->insert([
            'entity_type' => 'ledger_transaction', 'prefix' => '',
            'next_number' => (int) (DB::table('public_id_sequences')->where('entity_type', 'collection_receipt')->value('next_number') ?? 1),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_transaction_references')->exists()) {
            throw new RuntimeException('Financial transaction references cannot be removed by rollback.');
        }

        DB::table('public_id_sequences')->where('entity_type', 'ledger_transaction')->delete();
        Schema::dropIfExists('ledger_transaction_projections');
        Schema::dropIfExists('ledger_transaction_references');
        Schema::dropIfExists('ledger_projection_state');
    }
};
