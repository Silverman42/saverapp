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
        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->foreignId('thrift_plan_id')->nullable()->constrained('thrift_plans')->restrictOnDelete();
        });
        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('withdrawal_id', 32)->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('live_thrift_plan_id')->nullable()->constrained('thrift_plans')->restrictOnDelete()->unique();
            $table->foreignId('initiating_agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('fee_snapshot_id')->constrained('fee_snapshots')->restrictOnDelete();
            $table->foreignId('withdrawal_reservation_id')->nullable()->unique()->constrained('withdrawal_reservations')->restrictOnDelete();
            $table->string('type', 24);
            $table->string('state', 32);
            $table->boolean('held')->default(false);
            $table->string('hold_reason', 100)->nullable();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('hold_lifted_at')->nullable();
            $table->unsignedBigInteger('gross_amount_kobo');
            $table->unsignedBigInteger('fee_amount_kobo');
            $table->unsignedBigInteger('net_amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('method', 30);
            $table->string('destination_reference', 200);
            $table->string('destination_mask', 100);
            $table->string('reason', 500);
            $table->text('internal_notes')->nullable();
            $table->unsignedInteger('customer_version');
            $table->unsignedInteger('assignment_version');
            $table->unsignedInteger('plan_version');
            $table->unsignedInteger('business_version');
            $table->unsignedInteger('method_version');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('terminal_at')->nullable();
            $table->timestamps();
            $table->index(['customer_profile_id', 'submitted_at', 'id']);
            $table->index(['state', 'deadline_at', 'id']);
        });
        Schema::create('withdrawal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('withdrawal_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('operation', 24);
            $table->char('payload_hash', 64);
            $table->timestamps();
        });
        Schema::create('withdrawal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->unsignedInteger('request_version');
            $table->string('reason', 500)->nullable();
            $table->string('customer_explanation', 500)->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->index(['withdrawal_request_id', 'effective_at', 'id']);
        });
        Schema::create('withdrawal_notification_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('withdrawal_event_id')->constrained('withdrawal_events')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('channel', 20);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
            $table->unique(['withdrawal_event_id', 'recipient_user_id', 'channel'], 'withdrawal_notice_once');
        });
        DB::table('public_id_sequences')->insert(['entity_type' => 'withdrawal', 'prefix' => 'WDL-', 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('withdrawal_requests')->exists()) {
            throw new RuntimeException('Withdrawal requests and reservation evidence cannot be removed by rollback.');
        }
        DB::table('public_id_sequences')->where('entity_type', 'withdrawal')->delete();
        Schema::dropIfExists('withdrawal_notification_intents');
        Schema::dropIfExists('withdrawal_events');
        Schema::dropIfExists('withdrawal_attempts');
        Schema::dropIfExists('withdrawal_requests');
        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('thrift_plan_id');
        });
    }
};
