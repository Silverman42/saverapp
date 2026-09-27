<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'lifecycle_access_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedInteger('lifecycle_access_version')->default(0);
            });
        }
        if (! Schema::hasColumn('agent_offboarding_cases', 'version')) {
            Schema::table('agent_offboarding_cases', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
                $table->string('original_account_state', 30)->nullable();
                $table->string('original_operational_status', 20)->nullable();
                $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('reason')->nullable();
                $table->string('agent_facing_explanation', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
            });
        }
        if (! Schema::hasTable('agent_lifecycle_histories')) {
            Schema::create('agent_lifecycle_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_profile_id')->constrained()->restrictOnDelete();
                $table->foreignId('agent_offboarding_case_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
                $table->string('event_type', 40);
                $table->string('from_account_state', 30);
                $table->string('to_account_state', 30);
                $table->string('from_operational_status', 20);
                $table->string('to_operational_status', 20);
                $table->unsignedInteger('from_version');
                $table->unsignedInteger('to_version');
                $table->text('reason');
                $table->string('agent_facing_explanation', 500);
                $table->json('facts');
                $table->timestamp('created_at');
                $table->index(['agent_profile_id', 'id']);
            });
        }
        if (! Schema::hasTable('agent_lifecycle_operations')) {
            Schema::create('agent_lifecycle_operations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('attempt_reference')->unique();
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('agent_profile_id')->constrained()->restrictOnDelete();
                $table->string('action', 30);
                $table->char('payload_hash', 64);
                $table->json('result');
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('agent_lifecycle_notification_intents')) {
            Schema::create('agent_lifecycle_notification_intents', function (Blueprint $table): void {
                $table->id();
                $table->uuid('notification_id');
                $table->foreignId('agent_lifecycle_history_id');
                $table->foreignId('recipient_user_id');
                $table->foreignId('agent_profile_id');
                $table->foreignId('customer_profile_id')->nullable();
                $table->string('audience_type', 30);
                $table->string('channel', 16);
                $table->json('payload');
                $table->string('status', 20)->default('pending');
                $table->string('failure_reason')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('suppressed_at')->nullable();
                $table->timestamps();
            });
        }

        $foreignKeys = array_column(Schema::getForeignKeys('agent_lifecycle_notification_intents'), 'name');
        foreach (['agent_lifecycle_history_id' => ['agent_lifecycle_histories', 'agent_lifecycle_notice_history_fk'],
            'recipient_user_id' => ['users', 'agent_lifecycle_notice_recipient_fk'],
            'agent_profile_id' => ['agent_profiles', 'agent_lifecycle_notice_agent_fk'],
            'customer_profile_id' => ['customer_profiles', 'agent_lifecycle_notice_customer_fk']] as $column => [$target, $name]) {
            if (! in_array($name, $foreignKeys, true)) {
                Schema::table('agent_lifecycle_notification_intents', function (Blueprint $table) use ($column, $target, $name): void {
                    $table->foreign($column, $name)->references('id')->on($target)->restrictOnDelete();
                });
            }
        }
        $indexes = array_column(Schema::getIndexes('agent_lifecycle_notification_intents'), 'name');
        if (! in_array('agent_lifecycle_notice_uuid_unique', $indexes, true)) {
            Schema::table('agent_lifecycle_notification_intents', function (Blueprint $table): void {
                $table->unique('notification_id', 'agent_lifecycle_notice_uuid_unique');
            });
        }
        if (! in_array('agent_lifecycle_notice_unique', $indexes, true)) {
            Schema::table('agent_lifecycle_notification_intents', function (Blueprint $table): void {
                $table->unique(['agent_lifecycle_history_id', 'recipient_user_id', 'channel'], 'agent_lifecycle_notice_unique');
            });
        }

    }

    public function down(): void
    {
        if (DB::table('agent_lifecycle_histories')->exists() || DB::table('agent_lifecycle_operations')->exists()) {
            throw new RuntimeException('Retained Agent lifecycle evidence requires a reviewed forward migration.');
        }
        Schema::dropIfExists('agent_lifecycle_notification_intents');
        Schema::dropIfExists('agent_lifecycle_operations');
        Schema::dropIfExists('agent_lifecycle_histories');
        Schema::table('agent_offboarding_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initiated_by_user_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['version', 'original_account_state', 'original_operational_status', 'reason', 'agent_facing_explanation', 'started_at', 'completed_at', 'cancelled_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('lifecycle_access_version');
        });
    }
};
