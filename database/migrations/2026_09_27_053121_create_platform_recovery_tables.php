<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_recovery_work', function (Blueprint $table): void {
            $table->id();
            $table->string('owner', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('adapter_version')->default(1);
            $table->unsignedInteger('schema_version')->default(1);
            $table->char('payload_hash', 64);
            $table->string('operation_key', 100)->unique();
            $table->uuid('correlation_reference');
            $table->string('state', 30);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('cycle_attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->unsignedInteger('lease_seconds')->default(60);
            $table->unsignedInteger('backoff_seconds')->default(30);
            $table->unsignedInteger('backoff_cap_seconds')->default(300);
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->uuid('lease_owner')->nullable();
            $table->unsignedBigInteger('lease_token')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->uuid('replay_run_id')->nullable();
            $table->string('checkpoint', 100)->nullable();
            $table->string('result_reference', 150)->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->timestamps();
            $table->unique(['owner', 'source_id']);
            $table->index(['owner', 'state', 'available_at'], 'recovery_due_index');
            $table->index(['state', 'lease_expires_at'], 'recovery_expired_index');
        });
        Schema::create('platform_recovery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_id');
            $table->unsignedBigInteger('lease_token');
            $table->unsignedInteger('attempt_number');
            $table->string('phase', 20);
            $table->string('outcome', 30);
            $table->string('failure_code', 60)->nullable();
            $table->string('result_reference', 150)->nullable();
            $table->uuid('replay_run_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['work_id', 'lease_token', 'phase'], 'recovery_attempt_phase_unique');
        });
        Schema::create('platform_replay_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->default(1);
            $table->json('manifest');
            $table->char('digest', 64);
            $table->longText('evidence');
            $table->timestamp('created_at');
        });
        Schema::create('platform_replay_approvals', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->uuid('run_id')->unique();
            $table->char('digest', 64);
            $table->longText('evidence');
            $table->timestamp('created_at');
        });
        Schema::create('platform_replay_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state', 30);
            $table->unsignedInteger('cursor')->default(0);
            $table->timestamp('next_item_at', 6)->nullable();
            $table->timestamps();
        });
        Schema::create('platform_replay_progress', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedInteger('item_index');
            $table->unsignedBigInteger('work_id');
            $table->string('outcome', 30);
            $table->timestamp('created_at');
            $table->unique(['run_id', 'item_index']);
        });
        Schema::create('platform_recovery_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->char('input_hash', 64);
            $table->json('result');
            $table->unsignedBigInteger('audit_event_id');
            $table->timestamp('created_at');
        });
        Schema::create('platform_recovery_adoption', function (Blueprint $table): void {
            $table->string('owner', 40)->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('updated_at');
        });
        foreach (['audit_projection', 'notification_inbox'] as $owner) {
            DB::table('platform_recovery_adoption')->insert(['owner' => $owner, 'cursor' => 0, 'updated_at' => now()]);
        }
        foreach (['platform_recovery_attempts', 'platform_replay_manifests', 'platform_replay_approvals', 'platform_replay_progress', 'platform_recovery_operations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_'.strtolower($operation);
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Recovery evidence is immutable'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery evidence is immutable'");
                }
            }
        }
        $fields = ['owner', 'source_id', 'source_version', 'adapter_version', 'schema_version', 'payload_hash', 'operation_key', 'correlation_reference',
            'max_attempts', 'timeout_seconds', 'lease_seconds', 'backoff_seconds', 'backoff_cap_seconds', 'deadline_at', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $condition = implode(' OR ', array_map(fn (string $field): string => "OLD.{$field} IS NOT NEW.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER recovery_work_identity BEFORE UPDATE ON platform_recovery_work WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Recovery identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER recovery_work_no_delete BEFORE DELETE ON platform_recovery_work BEGIN SELECT RAISE(ABORT, 'Recovery evidence is retained'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $condition = implode(' OR ', array_map(fn (string $field): string => "NOT (OLD.{$field} <=> NEW.{$field})", $fields));
            DB::unprepared("CREATE TRIGGER recovery_work_identity BEFORE UPDATE ON platform_recovery_work FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery identity is immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER recovery_work_no_delete BEFORE DELETE ON platform_recovery_work FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery evidence is retained'");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery evidence requires a reviewed forward migration.');
    }
};
