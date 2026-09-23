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
        if (DB::table('fee_obligations')->where('status', '!=', 'pending')->exists()
            || DB::table('fee_obligations')->where('amount_kobo', '<', 1)->exists()) {
            throw new RuntimeException('Fee obligations with settled, waived, or cancelled status lack entry evidence and cannot be safely upgraded. Reconcile those records before retrying.');
        }

        Schema::create('fee_obligation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 50);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->string('source_type', 50);
            $table->string('source_id', 100);
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->string('customer_description', 500)->nullable();
            $table->string('ledger_posting_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'entry_type']);
            $table->index(['fee_obligation_id', 'created_at', 'id']);
        });

        DB::table('fee_obligations')->orderBy('id')->each(function (object $obligation): void {
            DB::table('fee_obligation_entries')->insert([
                'fee_obligation_id' => $obligation->id,
                'entry_type' => 'assessment',
                'amount_kobo' => $obligation->amount_kobo,
                'currency' => $obligation->currency,
                'source_type' => 'legacy_assessment',
                'source_id' => (string) $obligation->id,
                'idempotency_key' => 'legacy-assessment-'.$obligation->id,
                'actor_user_id' => $obligation->created_by_user_id,
                'reason' => null,
                'customer_description' => $obligation->customer_description,
                'ledger_posting_reference' => null,
                'created_at' => $obligation->created_at,
                'updated_at' => $obligation->updated_at,
            ]);
        });

        Schema::table('fee_obligations', function (Blueprint $table): void {
            $table->dropIndex('fee_obligations_customer_profile_id_status_index');
            $table->dropColumn('status');
            $table->index(['customer_profile_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('fee_obligation_entries')->where('source_type', '!=', 'legacy_assessment')->exists()) {
            throw new RuntimeException('New fee obligation history cannot be safely reduced to the legacy status field.');
        }

        Schema::table('fee_obligations', function (Blueprint $table): void {
            $table->string('status', 50)->default('pending');
            $table->dropIndex('fee_obligations_customer_profile_id_kind_index');
            $table->index(['customer_profile_id', 'status']);
        });

        Schema::dropIfExists('fee_obligation_entries');
    }
};
