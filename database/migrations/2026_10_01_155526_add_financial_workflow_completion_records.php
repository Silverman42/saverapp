<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['thrift_plans' => ['predecessor_plan_id', 'open_customer_profile_id'], 'withdrawal_requests' => ['live_thrift_plan_id']] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasIndex($table, [$column], 'unique')) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($column));
                }
            }
        }
        Schema::table('collection_receipts', function (Blueprint $table): void {
            $table->foreignId('replacement_reversal_id')->nullable()->unique()->constrained('reversal_requests');
        });
        Schema::create('financial_workflow_supplements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_reference')->unique();
            $table->string('payload_hash', 64);
            $table->string('kind', 40);
            $table->foreignId('customer_profile_id')->nullable()->constrained();
            $table->foreignId('thrift_plan_id')->nullable()->constrained();
            $table->foreignId('collection_batch_id')->nullable()->constrained();
            $table->foreignId('reversal_request_id')->nullable()->constrained();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->json('facts');
            $table->text('evidence');
            $table->timestamp('created_at');
            $table->index(['thrift_plan_id', 'kind']);
        });
        Schema::table('fee_refunds', function (Blueprint $table): void {
            $table->foreignId('compensation_posting_group_id')->nullable()->constrained('ledger_posting_groups');
            $table->unique(['compensation_posting_group_id', 'fee_obligation_id'], 'corrected_fee_refund_unique');
        });
        Schema::table('cash_recoveries', function (Blueprint $table): void {
            $table->index('cash_execution_id', 'cash_recoveries_execution_history');
        });
        Schema::table('cash_recoveries', function (Blueprint $table): void {
            $table->dropUnique('cash_recoveries_cash_execution_id_unique');
            $table->unsignedBigInteger('cash_execution_id')->nullable()->change();
            $table->foreignId('cash_disbursement_id')->nullable()->constrained();
            $table->string('event_type', 30)->default('return');
            $table->foreignId('return_posting_group_id')->nullable()->unique()->constrained('ledger_posting_groups');
        });
        DB::table('ledger_accounts')->insertOrIgnore([
            'code' => 'cash_recovery_clearing_ngn', 'account_class' => 'cash_recovery_clearing', 'normal_balance' => 'credit',
            'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'version' => 1, 'display_name' => 'Returned cash recovery clearing',
            'purpose' => 'Confirmed returned cash awaiting full linked compensation.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('platform_recovery_adoption')->insertOrIgnore(['owner' => 'financial_artifact', 'cursor' => 0, 'updated_at' => now()]);
        Schema::create('financial_artifact_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_artifact_id')->constrained();
            $table->unsignedInteger('source_version');
            $table->string('event_type', 20);
            $table->timestamp('created_at');
            $table->unique(['financial_artifact_id', 'source_version', 'event_type'], 'artifact_event_unique');
        });
        Schema::create('financial_artifact_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('financial_artifact_event_id')->constrained(indexName: 'artifact_intent_event_fk');
            $table->foreignId('recipient_user_id')->constrained('users', indexName: 'artifact_intent_recipient_fk');
            $table->foreignId('customer_profile_id')->nullable()->constrained(indexName: 'artifact_intent_customer_fk');
            $table->string('audience_type', 30);
            $table->string('channel', 16)->default('database');
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['financial_artifact_event_id', 'recipient_user_id'], 'artifact_intent_unique');
        });
        Schema::create('financial_release_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('capability', 60);
            $table->unsignedInteger('version');
            $table->string('owner_role', 80);
            $table->string('state', 30);
            $table->string('evidence_hash', 64);
            $table->string('dependency_hash', 64);
            $table->timestamp('valid_until');
            $table->text('evidence');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamp('created_at');
            $table->unique(['capability', 'owner_role', 'version']);
        });
        if (DB::getDriverName() === 'mysql') {
            foreach (['financial_workflow_supplements', 'financial_artifact_events', 'financial_release_evidence'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    $name = $table.'_'.strtolower($action).'_blocked';
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable financial evidence'");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Financial completion evidence requires a forward fix; immutable history cannot be dropped.');
    }
};
