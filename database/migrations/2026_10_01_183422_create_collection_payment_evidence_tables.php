<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_method_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('publication_reference')->unique();
            $table->char('payload_hash', 64);
            $table->string('method_key', 32);
            $table->unsignedInteger('version');
            $table->string('label', 100);
            $table->string('custody_account_code', 64);
            $table->unsignedInteger('mapping_version');
            $table->string('destination_key', 100);
            $table->boolean('attachment_required');
            $table->timestamp('effective_at');
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['method_key', 'version'], 'collection_method_version_unique');
        });
        Schema::create('collection_payment_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('evidence_reference')->unique();
            $table->char('payload_hash', 64);
            $table->char('reference_hash', 64)->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('customer_assignments')->restrictOnDelete();
            $table->foreignId('recording_agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('collection_method_version_id')->constrained()->restrictOnDelete();
            $table->string('method_reference', 120);
            $table->date('received_date');
            $table->string('timezone', 64);
            $table->unsignedBigInteger('amount_kobo');
            $table->text('source_attestation');
            $table->timestamp('created_at');
            $table->index(['customer_profile_id', 'id']);
        });
        Schema::create('collection_evidence_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_payment_evidence_id')->constrained('collection_payment_evidence', indexName: 'collection_file_evidence_fk')->restrictOnDelete();
            $table->string('storage_path')->unique();
            $table->char('checksum', 64);
            $table->string('mime_type', 50);
            $table->unsignedInteger('byte_size');
            $table->string('scanner_version', 100);
            $table->timestamp('scanned_at');
            $table->timestamp('created_at');
        });
        Schema::create('collection_evidence_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_reference')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('collection_payment_evidence_id')->constrained('collection_payment_evidence', indexName: 'collection_review_evidence_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('outcome', 20);
            $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['collection_payment_evidence_id', 'version'], 'collection_review_version_unique');
        });
        Schema::table('collection_receipts', function (Blueprint $table): void {
            $table->foreignId('collection_payment_evidence_id')->nullable()->unique()->constrained('collection_payment_evidence', indexName: 'collection_receipt_evidence_fk')->restrictOnDelete();
        });
        foreach (['business_bank_ngn' => 'Business bank', 'payment_clearing_ngn' => 'Payment clearing'] as $code => $name) {
            DB::table('ledger_accounts')->insertOrIgnore([
                'code' => $code, 'account_class' => 'asset', 'currency' => 'NGN',
                'normal_balance' => 'debit', 'mapping_status' => 'unconfigured', 'version' => 1,
                'display_name' => $name, 'purpose' => $name, 'supported_dimensions' => json_encode(['business', 'customer']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['collection_method_versions', 'collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews'] as $index => $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = 'collection_evidence_'.$index.'_'.strtolower($operation);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable collection evidence'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable collection evidence'); END");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Collection evidence must be preserved. Use a forward migration.');
    }
};
