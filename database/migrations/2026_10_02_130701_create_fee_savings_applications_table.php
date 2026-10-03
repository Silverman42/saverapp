<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_savings_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_reference')->unique();
            $table->char('payload_hash', 64);
            $table->char('preview_fingerprint', 64);
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('remaining_cycle_savings_kobo');
            $table->unsignedBigInteger('remaining_available_kobo');
            $table->unsignedBigInteger('remaining_fee_kobo');
            $table->string('currency', 3);
            $table->text('reason');
            $table->string('customer_description', 500);
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::statement('CREATE TRIGGER fee_savings_no_'.strtolower($operation).' BEFORE '.$operation." ON fee_savings_applications BEGIN SELECT RAISE(ABORT, 'Fee savings applications are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER fee_savings_no_'.strtolower($operation).' BEFORE '.$operation." ON fee_savings_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fee savings applications are immutable'");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_savings_applications');
    }
};
