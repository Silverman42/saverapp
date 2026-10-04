<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reversal_evidence_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_request_id')->constrained()->restrictOnDelete();
            $table->string('storage_path', 100)->unique();
            $table->char('checksum', 64);
            $table->string('mime_type', 40);
            $table->unsignedInteger('byte_size');
            $table->string('scanner_version', 100);
            $table->timestamp('scanned_at');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index('reversal_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Reversal evidence history requires a forward migration.');
    }
};
