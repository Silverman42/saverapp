<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record each plan's creation local date and backdate reason on its terms revisions (TPC-U02).
     */
    public function up(): void
    {
        Schema::table('plan_terms_revisions', function (Blueprint $table) {
            $table->date('created_local_date')->nullable()->after('start_date');
            $table->string('backdate_reason', 500)->nullable()->after('created_local_date');
        });

        DB::table('plan_terms_revisions')
            ->join('thrift_plans', 'thrift_plans.id', '=', 'plan_terms_revisions.thrift_plan_id')
            ->select('plan_terms_revisions.id', 'plan_terms_revisions.timezone', 'thrift_plans.created_at')
            ->orderBy('plan_terms_revisions.id')
            ->chunk(500, function ($revisions): void {
                foreach ($revisions as $revision) {
                    DB::table('plan_terms_revisions')->where('id', $revision->id)->update([
                        'created_local_date' => CarbonImmutable::parse($revision->created_at, 'UTC')
                            ->setTimezone($revision->timezone)->toDateString(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_terms_revisions', function (Blueprint $table) {
            $table->dropColumn(['created_local_date', 'backdate_reason']);
        });
    }
};
