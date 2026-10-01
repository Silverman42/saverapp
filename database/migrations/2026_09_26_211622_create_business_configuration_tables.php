<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::connection()->pretending() && DB::table('business_profiles')->count() !== 1) {
            throw new RuntimeException('Exactly one trusted business profile is required.');
        }
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('singleton_key')->default(1)->unique();
            $table->unsignedBigInteger('effective_configuration_id')->nullable();
        });
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->unsignedInteger('business_version')->nullable();
        });
        Schema::create('business_configuration_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_profile_id')->constrained();
            $table->unsignedInteger('version');
            $table->unsignedInteger('base_version');
            $table->longText('values');
            $table->char('values_hash', 64);
            $table->json('changed_codes');
            $table->char('dependency_hash', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->text('actor_label')->nullable();
            $table->text('reason');
            $table->string('source', 30);
            $table->timestamp('requested_effective_at', 6);
            $table->timestamp('created_at', 6);
            $table->unique(['business_profile_id', 'version'], 'business_config_version_unique');
        });
        Schema::create('business_configuration_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_profile_id')->constrained();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('base_version');
            $table->longText('patch');
            $table->longText('preview')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->index(['actor_user_id', 'status']);
        });
        Schema::create('business_configuration_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('configuration_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('event_type', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('audit_event_id');
            $table->json('changed_codes');
            $table->timestamp('created_at', 6);
        });
        Schema::create('business_configuration_work', function (Blueprint $table): void {
            $table->unsignedBigInteger('configuration_id')->primary();
            $table->string('status', 30);
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('effective_at', 6)->nullable();
            $table->timestamp('updated_at', 6);
            $table->index(['status', 'configuration_id']);
        });
        Schema::create('business_configuration_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('configuration_id');
            $table->string('consumer', 50);
            $table->char('dependency_hash', 64);
            $table->timestamp('created_at', 6);
            $table->unique(['configuration_id', 'consumer'], 'business_config_ack_unique');
        });
        Schema::create('business_configuration_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->foreignId('business_profile_id')->constrained();
            $table->uuid('operation_id');
            $table->string('action', 30);
            $table->char('input_hash', 64);
            $table->longText('result');
            $table->timestamp('created_at', 6);
            $table->unique(['actor_user_id', 'operation_id'], 'business_config_operation_unique');
        });
        Schema::create('business_settings_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_configuration_event_id');
            $table->foreignId('recipient_user_id')->constrained('users');
            $table->uuid('notification_id')->unique();
            $table->string('audience_type', 40)->default('settings_manager');
            $table->string('status', 30)->default('pending');
            $table->timestamps();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->unique(['business_configuration_event_id', 'recipient_user_id'], 'business_settings_notice_unique');
        });
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::unprepared('ALTER TABLE business_profiles ADD CONSTRAINT business_singleton_check CHECK (singleton_key = 1)');
        }
        foreach (['business_configuration_versions' => 'cfg_versions', 'business_configuration_events' => 'cfg_events', 'business_configuration_operations' => 'cfg_operations', 'business_configuration_acknowledgements' => 'cfg_acknowledgements'] as $table => $prefix) {
            foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $suffix) {
                $name = $prefix.'_'.$suffix;
                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Configuration evidence is immutable'); END");
                } elseif ($driver === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Configuration evidence is immutable'");
                }
            }
        }
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER business_identity_delete BEFORE DELETE ON business_profiles BEGIN SELECT RAISE(ABORT, 'Business identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER business_singleton_insert BEFORE INSERT ON business_profiles WHEN NEW.singleton_key != 1 BEGIN SELECT RAISE(ABORT, 'Invalid business singleton'); END");
            DB::unprepared("CREATE TRIGGER business_identity_update BEFORE UPDATE ON business_profiles WHEN NEW.business_id != OLD.business_id OR NEW.singleton_key != 1 BEGIN SELECT RAISE(ABORT, 'Business identity is immutable'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER business_identity_delete BEFORE DELETE ON business_profiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business identity is immutable'");
            DB::unprepared("CREATE TRIGGER business_identity_update BEFORE UPDATE ON business_profiles FOR EACH ROW BEGIN IF NEW.business_id <> OLD.business_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business identity is immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Configuration evidence requires a reviewed forward migration; destructive rollback is prohibited.');
    }
};
