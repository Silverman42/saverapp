<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->unsignedBigInteger('legacy_audit_event_id')->nullable()->unique();
            $table->string('operation_key', 64)->nullable()->unique();
            $table->string('event_type', 100);
            $table->string('category', 40);
            $table->string('severity', 20)->default('Informational');
            $table->string('outcome', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 50);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_reference', 100)->nullable();
            $table->string('source_module', 40);
            $table->unsignedInteger('source_version');
            $table->string('required_permission', 100)->nullable();
            $table->string('correlation_reference', 100)->nullable();
            $table->string('retention_class', 40);
            $table->unsignedInteger('schema_version');
            $table->boolean('legacy_evidence')->default(false);
            $table->json('content');
            $table->char('content_hash', 64);
            $table->char('input_hash', 64);
            $table->timestamp('occurred_at', 6);
            $table->timestamp('recorded_at', 6);
            $table->index(['recorded_at', 'event_id']);
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('audit_protected_payloads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_event_id')->unique();
            $table->longText('ciphertext');
            $table->string('key_id', 100);
            $table->timestamp('created_at', 6);
        });
        Schema::create('audit_projection_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('active_version')->default(1);
            $table->unsignedBigInteger('watermark')->default(0);
            $table->unsignedInteger('rebuild_version')->nullable();
            $table->unsignedBigInteger('rebuild_cursor')->default(0);
            $table->unsignedBigInteger('owner_scan_cursor')->default(0);
            $table->string('status', 30)->default('current');
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('audit_projection_state')->insert(['id' => 1, 'active_version' => 1, 'watermark' => 0, 'status' => 'current']);
        Schema::create('audit_projection_work', function (Blueprint $table): void {
            $table->unsignedBigInteger('canonical_event_id')->primary();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 30)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['status', 'available_at']);
        });
        Schema::create('audit_search_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_event_id');
            $table->unsignedInteger('index_version');
            $table->ulid('event_id');
            $table->string('event_type', 100);
            $table->string('category', 40);
            $table->string('severity', 20);
            $table->string('outcome', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 50);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_reference', 100)->nullable();
            $table->string('source_module', 40);
            $table->string('required_permission', 100)->nullable();
            $table->string('correlation_reference', 100)->nullable();
            $table->string('retention_class', 40);
            $table->boolean('legacy_evidence');
            $table->char('content_hash', 64);
            $table->timestamp('recorded_at', 6);
            $table->timestamp('indexed_at', 6);
            $table->unique(['index_version', 'canonical_event_id']);
            $table->index(['index_version', 'recorded_at', 'event_id']);
            $table->index(['index_version', 'category', 'recorded_at']);
            $table->index(['index_version', 'actor_id', 'recorded_at']);
            $table->index(['index_version', 'target_reference']);
        });
        Schema::create('audit_import_results', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_audit_event_id')->primary();
            $table->string('status', 30);
            $table->string('diagnostic_code', 50)->nullable();
            $table->timestamp('updated_at');
        });
        Schema::create('security_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('case_reference')->unique();
            $table->char('source_key', 64)->unique();
            $table->unsignedBigInteger('source_event_id');
            $table->unsignedBigInteger('affected_user_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('state', 30)->default('Open');
            $table->string('severity', 20);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('episode')->default(1);
            $table->timestamps();
            $table->index(['state', 'severity', 'created_at']);
        });
        Schema::create('security_case_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('security_case_id');
            $table->unsignedInteger('version');
            $table->string('event_type', 100);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('audit_event_id');
            $table->json('facts');
            $table->text('note_ciphertext')->nullable();
            $table->json('evidence_references')->nullable();
            $table->timestamp('created_at');
            $table->unique(['security_case_id', 'version']);
        });
        Schema::create('security_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('security_case_transition_id');
            $table->unsignedBigInteger('recipient_user_id');
            $table->uuid('notification_id')->unique();
            $table->string('channel', 20)->default('database');
            $table->string('audience_type', 50)->default('security_operations_admin');
            $table->string('status', 30)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['security_case_transition_id', 'recipient_user_id'], 'security_notice_recipient_unique');
        });
        foreach (['canonical_audit_events', 'audit_protected_payloads', 'security_case_transitions'] as $table) {
            if (DB::getDriverName() === 'mysql') {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    $name = $table.'_no_'.strtolower($action);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only audit evidence'");
                }
            } elseif (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    $name = $table.'_no_'.strtolower($action);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT, 'Append-only audit evidence'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['security_notification_intents', 'security_case_transitions', 'security_cases', 'audit_import_results', 'audit_search_documents', 'audit_projection_work', 'audit_projection_state', 'audit_protected_payloads', 'canonical_audit_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
