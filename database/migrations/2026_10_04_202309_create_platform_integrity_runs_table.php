<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integrity runs are immutable evidence: each run is inserted once with its complete results, and a later run supersedes it.
     */
    public function up(): void
    {
        Schema::create('platform_integrity_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_reference')->unique();
            $table->string('scope', 20);
            $table->string('status', 20);
            $table->json('failed_domains');
            $table->longText('results');
            $table->char('digest', 64);
            $table->string('operator', 100);
            $table->timestamp('created_at');
            $table->index(['scope', 'id']);
        });
        $driver = DB::getDriverName();
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'platform_integrity_runs_'.strtolower($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON platform_integrity_runs BEGIN SELECT RAISE(ABORT, 'Platform evidence is immutable'); END");
            } elseif ($driver === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON platform_integrity_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform evidence is immutable'");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Platform integrity evidence requires a forward migration.');
    }
};
