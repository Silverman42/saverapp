<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ledger_accounts', 'version')) {
            Schema::table('ledger_accounts', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
        DB::table('ledger_accounts')->whereIn('code', [
            'business_cash_ngn', 'agent_receivable_ngn', 'customer_savings_liability_ngn', 'fee_income_ngn',
        ])->update(['mapping_status' => 'mapped', 'version' => 1, 'updated_at' => now()]);
        if (! DB::table('public_id_sequences')->where('entity_type', 'collection_receipt')->exists()) {
            DB::table('public_id_sequences')->insert([
                'entity_type' => 'collection_receipt', 'prefix' => '', 'next_number' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('withdrawal_reservations')) {
            Schema::create('withdrawal_reservations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
                $table->string('owner_reference', 100)->unique();
                $table->unsignedBigInteger('gross_amount_kobo');
                $table->string('status', 20);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->index(['customer_profile_id', 'status']);
            });
        }
        if (! Schema::hasTable('collection_batches')) {
            Schema::create('collection_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_profile_id')->constrained()->restrictOnDelete();
                $table->date('received_date');
                $table->string('timezone', 64);
                $table->unsignedSmallInteger('revision');
                $table->foreignId('predecessor_batch_id')->nullable()->constrained('collection_batches')->restrictOnDelete();
                $table->string('status', 24)->default('open');
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('frozen_at')->nullable();
                $table->timestamps();
                $table->unique(['agent_profile_id', 'received_date', 'timezone', 'revision'], 'collection_batch_identity');
            });
        }
        if (! Schema::hasTable('collection_receipts')) {
            Schema::create('collection_receipts', function (Blueprint $table): void {
                $table->id();
                $table->string('receipt_reference', 40)->unique();
                $table->uuid('attempt_reference')->unique();
                $table->char('payload_hash', 64);
                $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
                $table->foreignId('thrift_plan_id')->nullable()->constrained('thrift_plans')->restrictOnDelete();
                $table->foreignId('recording_agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
                $table->foreignId('assignment_id')->constrained('customer_assignments')->restrictOnDelete();
                $table->foreignId('collection_batch_id')->constrained()->restrictOnDelete();
                $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('savings_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
                $table->date('received_date');
                $table->string('timezone', 64);
                $table->unsignedInteger('business_version');
                $table->unsignedBigInteger('tender_amount_kobo');
                $table->unsignedBigInteger('savings_amount_kobo');
                $table->unsignedBigInteger('fee_amount_kobo');
                $table->string('late_reason', 500)->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();
                $table->index(['customer_profile_id', 'received_date', 'id']);
            });
        }
        if (! Schema::hasTable('collection_allocations')) {
            Schema::create('collection_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
                $table->foreignId('contribution_slot_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount_kobo');
                $table->boolean('is_advance')->default(false);
                $table->timestamps();
                $table->unique(['collection_receipt_id', 'contribution_slot_id'], 'collection_allocation_once');
                $table->index(['contribution_slot_id', 'id']);
            });
        }
        if (! Schema::hasTable('collection_fee_components')) {
            Schema::create('collection_fee_components', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
                $table->foreignId('fee_obligation_id')->constrained()->restrictOnDelete();
                $table->foreignId('ledger_posting_group_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount_kobo');
                $table->timestamps();
                $table->unique(['collection_receipt_id', 'fee_obligation_id'], 'collection_fee_once');
            });
        }
        if (! Schema::hasTable('collection_annotations')) {
            Schema::create('collection_annotations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('contribution_slot_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('kind', 20);
                $table->string('reason', 500);
                $table->timestamps();
                $table->unique(['contribution_slot_id', 'version']);
            });
        }
        if (! Schema::hasTable('cash_remittances')) {
            Schema::create('cash_remittances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_batch_id')->constrained()->restrictOnDelete();
                $table->foreignId('agent_profile_id')->constrained()->restrictOnDelete();
                $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('ledger_posting_group_id')->nullable()->constrained('ledger_posting_groups')->restrictOnDelete();
                $table->string('handoff_reference', 100)->unique();
                $table->unsignedBigInteger('amount_kobo');
                $table->date('handoff_date');
                $table->string('receiving_location', 150);
                $table->string('source_attestation', 500);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('collection_batch_reviews')) {
            Schema::create('collection_batch_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_batch_id')->constrained()->restrictOnDelete();
                $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('batch_version');
                $table->string('outcome', 24);
                $table->unsignedBigInteger('expected_kobo');
                $table->unsignedBigInteger('remitted_kobo');
                $table->unsignedBigInteger('outstanding_kobo');
                $table->string('reason', 500);
                $table->timestamps();
                $table->unique(['collection_batch_id', 'batch_version'], 'batch_review_version_once');
            });
        }
        if (! Schema::hasIndex('collection_batch_reviews', 'batch_review_version_once')) {
            Schema::table('collection_batch_reviews', function (Blueprint $table): void {
                $table->unique(['collection_batch_id', 'batch_version'], 'batch_review_version_once');
            });
        }
        if (! Schema::hasTable('collection_exceptions')) {
            Schema::create('collection_exceptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_batch_id')->constrained()->restrictOnDelete();
                $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('kind', 30);
                $table->string('status', 24)->default('open');
                $table->unsignedBigInteger('amount_kobo');
                $table->string('reason', 500);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('collection_exception_events')) {
            Schema::create('collection_exception_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_exception_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('batch_version');
                $table->string('event_type', 24);
                $table->string('reason', 500);
                $table->timestamps();
                $table->unique(['collection_exception_id', 'batch_version'], 'exception_event_version_once');
            });
        }
        if (! Schema::hasTable('collection_notification_intents')) {
            Schema::create('collection_notification_intents', function (Blueprint $table): void {
                $table->id();
                $table->uuid('notification_id')->unique();
                $table->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
                $table->string('status', 20)->default('pending');
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('suppressed_at')->nullable();
                $table->timestamps();
                $table->unique(['collection_receipt_id', 'recipient_user_id'], 'collection_notice_recipient_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_notification_intents');
        Schema::dropIfExists('collection_exception_events');
        Schema::dropIfExists('collection_exceptions');
        Schema::dropIfExists('collection_batch_reviews');
        Schema::dropIfExists('cash_remittances');
        Schema::dropIfExists('collection_annotations');
        Schema::dropIfExists('collection_fee_components');
        Schema::dropIfExists('collection_allocations');
        Schema::dropIfExists('collection_receipts');
        Schema::dropIfExists('collection_batches');
        Schema::dropIfExists('withdrawal_reservations');
        DB::table('public_id_sequences')->where('entity_type', 'collection_receipt')->delete();
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
