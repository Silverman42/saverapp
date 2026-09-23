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
        if (DB::table('fee_rules')->where('kind', '!=', 'registration')->exists()
            || DB::table('fee_snapshots')->where('kind', '!=', 'registration')->exists()) {
            throw new RuntimeException('Existing non-registration fee data has no authoritative source identity and cannot be safely upgraded.');
        }

        if (DB::table('fee_rules')->whereNotIn('model', ['fixed', 'no_fee'])->exists()
            || DB::table('fee_snapshots')->whereNotIn('model', ['fixed', 'no_fee'])->exists()
            || DB::table('fee_rules')->where('currency', '!=', 'NGN')->exists()
            || DB::table('fee_snapshots')->where('currency', '!=', 'NGN')->exists()
            || DB::table('fee_rules')->where('model', 'fixed')->where('amount_kobo', '<', 1)->exists()
            || DB::table('fee_rules')->where('model', 'no_fee')->where('amount_kobo', '!=', 0)->exists()
            || DB::table('fee_snapshots')->where('model', 'fixed')->where('amount_kobo', '<', 1)->exists()
            || DB::table('fee_snapshots')->where('model', 'no_fee')->where('amount_kobo', '!=', 0)->exists()) {
            throw new RuntimeException('Existing fee terms do not match the approved NGN fixed/explicit-zero representation.');
        }

        $mismatchedSnapshots = DB::table('fee_snapshots')
            ->join('fee_rules', 'fee_snapshots.fee_rule_id', '=', 'fee_rules.id')
            ->whereColumn('fee_snapshots.fee_rule_version', '!=', 'fee_rules.version')
            ->orWhereColumn('fee_snapshots.amount_kobo', '!=', 'fee_rules.amount_kobo')
            ->orWhereColumn('fee_snapshots.currency', '!=', 'fee_rules.currency')
            ->exists();

        $mismatchedObligations = DB::table('fee_obligations')
            ->join('fee_snapshots', 'fee_obligations.fee_snapshot_id', '=', 'fee_snapshots.id')
            ->whereColumn('fee_obligations.kind', '!=', 'fee_snapshots.kind')
            ->orWhereColumn('fee_obligations.amount_kobo', '!=', 'fee_snapshots.amount_kobo')
            ->orWhereColumn('fee_obligations.currency', '!=', 'fee_snapshots.currency')
            ->exists();

        if ($mismatchedSnapshots || $mismatchedObligations) {
            throw new RuntimeException('Existing fee snapshots or obligations disagree with their immutable source terms. Reconcile before retrying.');
        }

        Schema::table('fee_rules', function (Blueprint $table): void {
            $table->string('rule_key', 100)->nullable()->after('kind');
            $table->string('timing', 50)->default('registration')->after('model');
            $table->string('basis', 50)->default('none')->after('timing');
            $table->unsignedSmallInteger('basis_points')->nullable()->after('amount_kobo');
            $table->string('settlement_source', 50)->default('external_receipt')->after('basis_points');
            $table->index(['kind', 'rule_key', 'effective_at'], 'fee_rules_kind_key_effective_index');
        });

        DB::table('fee_rules')->whereNull('rule_key')->update(['rule_key' => 'registration']);

        Schema::table('fee_rules', function (Blueprint $table): void {
            $table->string('rule_key', 100)->nullable(false)->change();
        });

        Schema::table('fee_snapshots', function (Blueprint $table): void {
            $table->string('timing', 50)->default('registration')->after('model');
            $table->string('basis', 50)->default('none')->after('timing');
            $table->unsignedSmallInteger('basis_points')->nullable()->after('amount_kobo');
            $table->unsignedBigInteger('basis_amount_kobo')->default(0)->after('basis_points');
            $table->string('settlement_source', 50)->default('external_receipt')->after('basis_points');
            $table->string('source_type', 50)->nullable()->after('customer_profile_id');
            $table->string('source_id', 100)->nullable()->after('source_type');
        });

        DB::table('fee_snapshots')->orderBy('id')->each(function (object $snapshot): void {
            DB::table('fee_snapshots')->where('id', $snapshot->id)->update([
                'source_type' => 'registration',
                'source_id' => (string) $snapshot->customer_profile_id,
            ]);
        });

        Schema::table('fee_snapshots', function (Blueprint $table): void {
            $table->dropUnique('fee_snapshots_customer_profile_id_kind_unique');
            $table->string('source_type', 50)->nullable(false)->change();
            $table->string('source_id', 100)->nullable(false)->change();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::table('fee_obligations', function (Blueprint $table): void {
            $table->string('source_type', 50)->nullable()->after('fee_snapshot_id');
            $table->string('source_id', 100)->nullable()->after('source_type');
        });

        DB::table('fee_obligations')
            ->join('fee_snapshots', 'fee_obligations.fee_snapshot_id', '=', 'fee_snapshots.id')
            ->select('fee_obligations.id', 'fee_snapshots.source_type', 'fee_snapshots.source_id')
            ->orderBy('fee_obligations.id')
            ->each(function (object $obligation): void {
                DB::table('fee_obligations')->where('id', $obligation->id)->update([
                    'source_type' => $obligation->source_type,
                    'source_id' => $obligation->source_id,
                ]);
            });

        Schema::table('fee_obligations', function (Blueprint $table): void {
            $table->dropUnique('fee_obligations_customer_profile_id_kind_unique');
            $table->string('source_type', 50)->nullable(false)->change();
            $table->string('source_id', 100)->nullable(false)->change();
            $table->unique(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('fee_snapshots')->where('kind', '!=', 'registration')->exists()
            || DB::table('fee_obligations')->where('kind', '!=', 'registration')->exists()
            || DB::table('fee_rules')->where('kind', '!=', 'registration')->exists()) {
            throw new RuntimeException('Plan fee data cannot be represented by the original registration-only schema.');
        }

        Schema::table('fee_obligations', function (Blueprint $table): void {
            $table->dropUnique('fee_obligations_source_type_source_id_unique');
            $table->unique(['customer_profile_id', 'kind']);
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('fee_snapshots', function (Blueprint $table): void {
            $table->dropUnique('fee_snapshots_source_type_source_id_unique');
            $table->unique(['customer_profile_id', 'kind']);
            $table->dropColumn(['source_type', 'source_id', 'timing', 'basis', 'settlement_source', 'basis_points', 'basis_amount_kobo']);
        });

        Schema::table('fee_rules', function (Blueprint $table): void {
            $table->dropIndex('fee_rules_kind_key_effective_index');
            $table->dropColumn(['rule_key', 'timing', 'basis', 'basis_points', 'settlement_source']);
        });
    }
};
