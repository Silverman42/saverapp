<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_receipts', function (Blueprint $table): void {
            $table->string('method', 32)->default('cash');
            $table->string('method_label', 100)->default('Cash');
            $table->string('custody_account_code', 64)->default('agent_receivable_ngn');
            $table->string('method_reference', 120)->nullable();
            $table->foreignId('collection_method_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('collection_evidence_review_id')->nullable()->constrained('collection_evidence_reviews')->restrictOnDelete();
        });
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->string('method_identity', 64)->default('cash');
            $table->string('custody_account_code', 64)->default('agent_receivable_ngn');
            $table->foreignId('collection_method_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['agent_profile_id', 'received_date', 'timezone'], 'collection_batch_agent_date');
            $table->dropUnique('collection_batch_identity');
            $table->unique(['agent_profile_id', 'received_date', 'timezone', 'method_identity', 'revision'], 'collection_batch_identity');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Collection method history must be preserved. Use a forward migration.');
    }
};
