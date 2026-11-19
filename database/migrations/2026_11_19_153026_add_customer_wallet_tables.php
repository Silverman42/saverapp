<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 18 Customer wallet: the wallet liability account, the withdrawal source split, and the automatic completion sweep record.
 * The account starts unconfigured; finance maps it before the `wallet` feature can be activated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledger_accounts')->insertOrIgnore([
            'code' => 'customer_wallet_liability_ngn', 'account_class' => 'customer_wallet_liability', 'normal_balance' => 'credit',
            'currency' => 'NGN', 'mapping_status' => 'unconfigured', 'version' => 1, 'display_name' => 'Customer wallet',
            'purpose' => 'Customer money moved out of a thrift plan and not yet paid out or contributed to another plan.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->string('source', 16)->default('plan')->after('thrift_plan_id');
            $table->index(['customer_profile_id', 'source', 'status'], 'withdrawal_reservations_source_index');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->string('source', 16)->default('plan')->after('customer_profile_id');
            $table->string('initiated_by', 16)->default('agent')->after('source');
            $table->foreignId('live_wallet_customer_profile_id')->nullable()->unique()->after('live_thrift_plan_id')
                ->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('wallet_posting_group_id')->nullable()->unique()->after('withdrawal_reservation_id')
                ->constrained('ledger_posting_groups')->restrictOnDelete();
            $table->foreignId('thrift_plan_id')->nullable()->change();
            $table->foreignId('fee_snapshot_id')->nullable()->change();
            $table->foreignId('submitted_by_user_id')->nullable()->change();
            $table->unsignedInteger('plan_version')->nullable()->change();
            $table->index(['customer_profile_id', 'source']);
        });

        Schema::create('plan_wallet_sweeps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thrift_plan_id')->constrained('thrift_plans')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('completion_event_id')->unique()->constrained('plan_lifecycle_events')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('blocker', 40)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->foreignId('withdrawal_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_wallet_sweeps');
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropIndex(['customer_profile_id', 'source']);
            $table->dropConstrainedForeignId('live_wallet_customer_profile_id');
            $table->dropConstrainedForeignId('wallet_posting_group_id');
            $table->dropColumn(['source', 'initiated_by']);
        });
        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->dropIndex('withdrawal_reservations_source_index');
            $table->dropColumn('source');
        });
        DB::table('ledger_accounts')->where('code', 'customer_wallet_liability_ngn')->delete();
    }
};
