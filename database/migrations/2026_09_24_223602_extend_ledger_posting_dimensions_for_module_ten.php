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
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('display_name', 100)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->json('supported_dimensions')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('retired_at')->nullable();
        });
        Schema::table('ledger_posting_groups', function (Blueprint $table): void {
            $table->date('occurred_on')->nullable();
            $table->string('business_timezone', 64)->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('correlation_id', 100)->nullable();
            $table->foreignId('thrift_plan_id')->nullable()->constrained('thrift_plans')->restrictOnDelete();
            $table->index(['customer_profile_id', 'occurred_on', 'id'], 'ledger_group_customer_occurrence');
        });
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignId('thrift_plan_id')->nullable();
            $table->foreign('thrift_plan_id', 'ledger_entry_plan_fk')
                ->references('id')->on('thrift_plans')->restrictOnDelete();
            $table->index(['customer_profile_id', 'thrift_plan_id', 'id'], 'ledger_entry_customer_plan');
        });

        $accounts = [
            'business_cash_ngn' => ['Business cash', 'Confirmed business cash custody', ['business']],
            'agent_receivable_ngn' => ['Agent receivable', 'Money entrusted to the original recording Agent', ['agent']],
            'customer_savings_liability_ngn' => ['Customer savings liability', 'Posted principal owed to a Customer', ['customer', 'cycle']],
            'fee_income_ngn' => ['Fee income', 'Recognized agreed fee earnings', ['customer', 'obligation']],
            'refund_payable_ngn' => ['Refund payable', 'Approved external refund obligation', ['customer', 'obligation']],
            'other_deduction_destination_ngn' => ['Other deduction destination', 'Unconfigured named deduction destination', ['customer']],
        ];
        foreach ($accounts as $code => [$name, $purpose, $dimensions]) {
            DB::table('ledger_accounts')->where('code', $code)->update([
                'display_name' => $name, 'purpose' => $purpose,
                'supported_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                'effective_at' => now(),
            ]);
        }

        $liabilityAccountId = DB::table('ledger_accounts')
            ->where('code', 'customer_savings_liability_ngn')->value('id');
        foreach (DB::table('collection_receipts')->orderBy('id')->lazyById(100) as $receipt) {
            $groupIds = DB::table('collection_fee_components')
                ->where('collection_receipt_id', $receipt->id)->pluck('ledger_posting_group_id')->all();
            if ($receipt->savings_posting_group_id !== null) {
                $groupIds[] = $receipt->savings_posting_group_id;
            }
            $applicationId = DB::table('ledger_posting_groups')
                ->where('source_type', 'fee_application')->where('source_id', (string) $receipt->id)->value('id');
            if ($applicationId !== null) {
                $groupIds[] = $applicationId;
            }
            if ($groupIds === []) {
                continue;
            }
            DB::table('ledger_posting_groups')->whereIn('id', $groupIds)->update([
                'occurred_on' => $receipt->received_date,
                'business_timezone' => $receipt->timezone,
                'correlation_id' => 'collection-receipt-'.$receipt->id,
                'thrift_plan_id' => $receipt->thrift_plan_id,
            ]);
            if ($receipt->thrift_plan_id !== null && $liabilityAccountId !== null) {
                DB::table('ledger_entries')->whereIn('ledger_posting_group_id', $groupIds)
                    ->where('ledger_account_id', $liabilityAccountId)
                    ->update(['thrift_plan_id' => $receipt->thrift_plan_id]);
            }
        }
        foreach (DB::table('cash_remittances')->whereNotNull('ledger_posting_group_id')->orderBy('id')->lazyById(100) as $remittance) {
            $timezone = DB::table('collection_batches')->where('id', $remittance->collection_batch_id)->value('timezone');
            if ($timezone === null) {
                throw new RuntimeException('A remittance has no authoritative batch timezone.');
            }
            DB::table('ledger_posting_groups')->where('id', $remittance->ledger_posting_group_id)->update([
                'occurred_on' => $remittance->handoff_date,
                'business_timezone' => $timezone,
                'correlation_id' => 'cash-remittance-'.$remittance->id,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_posting_groups')->exists()) {
            throw new RuntimeException('Posted financial dimensions cannot be removed by rollback.');
        }
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_entry_customer_plan');
            $table->dropForeign('ledger_entry_plan_fk');
            $table->dropColumn('thrift_plan_id');
        });
        Schema::table('ledger_posting_groups', function (Blueprint $table): void {
            $table->dropIndex('ledger_group_customer_occurrence');
            $table->dropConstrainedForeignId('thrift_plan_id');
            $table->dropColumn(['occurred_on', 'business_timezone', 'schema_version', 'correlation_id']);
        });
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'purpose', 'supported_dimensions', 'effective_at', 'retired_at']);
        });
    }
};
