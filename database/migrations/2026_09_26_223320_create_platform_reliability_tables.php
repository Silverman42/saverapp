<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('mode', 30);
            $table->unsignedBigInteger('version');
            $table->unsignedInteger('catalogue_version');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at');
        });
        Schema::create('platform_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->unique();
            $table->string('from_mode', 30)->nullable();
            $table->string('to_mode', 30);
            $table->uuid('operation_id')->nullable()->unique();
            $table->longText('evidence');
            $table->unsignedBigInteger('audit_event_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('platform_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->char('input_hash', 64);
            $table->text('result');
            $table->timestamp('created_at');
        });
        Schema::create('platform_heartbeats', function (Blueprint $table): void {
            $table->string('component', 30)->primary();
            $table->timestamp('observed_at');
        });
        DB::table('platform_state')->insert(['id' => 1, 'mode' => 'normal', 'version' => 1, 'catalogue_version' => 1, 'updated_at' => now()]);
        DB::table('platform_transitions')->insert(['version' => 1, 'from_mode' => null, 'to_mode' => 'normal',
            'evidence' => 'Installation initialized by create_platform_reliability_tables; no business capabilities enabled.', 'created_at' => now()]);
        $driver = DB::getDriverName();
        foreach (['platform_transitions', 'platform_operations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_'.strtolower($operation);
                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Platform evidence is immutable'); END");
                } elseif ($driver === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform evidence is immutable'");
                }
            }
        }
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER platform_singleton_insert BEFORE INSERT ON platform_state WHEN NEW.id != 1 BEGIN SELECT RAISE(ABORT, 'Invalid platform singleton'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared('ALTER TABLE platform_state ADD CONSTRAINT platform_singleton_check CHECK (id = 1)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Platform evidence requires a reviewed forward migration.');
    }
};
