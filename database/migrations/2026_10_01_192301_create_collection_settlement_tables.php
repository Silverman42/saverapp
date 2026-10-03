<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_bank_reference_claims', function (Blueprint $table): void {
            $table->id();
            $table->char('reference_hash', 64)->unique();
            $table->string('source_type', 32);
            $table->uuid('source_reference');
            $table->timestamp('created_at');
        });
        DB::table('collection_payment_evidence as evidence')
            ->join('collection_method_versions as methods', 'methods.id', '=', 'evidence.collection_method_version_id')
            ->where('methods.custody_account_code', 'business_bank_ngn')->orderBy('evidence.id')
            ->select('evidence.id', 'evidence.method_reference', 'methods.destination_key', 'evidence.evidence_reference', 'evidence.created_at')
            ->chunkById(100, function ($evidence): void {
                foreach ($evidence as $claim) {
                    DB::table('collection_bank_reference_claims')->insert([
                        'reference_hash' => hash('sha256', json_encode(['destination' => $claim->destination_key, 'method' => 'transfer', 'reference' => $claim->method_reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'source_type' => 'payment_evidence',
                        'source_reference' => $claim->evidence_reference, 'created_at' => $claim->created_at,
                    ]);
                }
            }, 'evidence.id', 'id');
        Schema::create('collection_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('settlement_reference')->unique();
            $table->char('payload_hash', 64);
            $table->char('bank_reference_hash', 64)->unique();
            $table->foreignId('collection_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('batch_version');
            $table->foreignId('bank_method_version_id')->constrained('collection_method_versions')->restrictOnDelete();
            $table->unsignedInteger('clearing_mapping_version');
            $table->unsignedInteger('bank_mapping_version');
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ledger_posting_group_id')->unique()->constrained()->restrictOnDelete();
            $table->string('bank_reference', 120);
            $table->date('settled_date');
            $table->string('timezone', 64);
            $table->unsignedBigInteger('amount_kobo');
            $table->text('source_attestation');
            $table->text('reason');
            $table->timestamp('created_at');
        });
        Schema::create('collection_settlement_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_settlement_id')->constrained()->restrictOnDelete();
            $table->string('storage_path')->unique();
            $table->char('checksum', 64);
            $table->string('mime_type', 50);
            $table->unsignedInteger('byte_size');
            $table->string('scanner_version', 100);
            $table->timestamp('scanned_at');
            $table->timestamp('created_at');
        });
        foreach (['collection_settlements', 'collection_settlement_files', 'collection_bank_reference_claims'] as $index => $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = 'collection_settlement_'.$index.'_'.strtolower($operation);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable clearing settlement'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable clearing settlement'); END");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Settlement history must be preserved. Use a forward migration.');
    }
};
