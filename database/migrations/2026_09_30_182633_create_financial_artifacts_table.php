<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supersedes_artifact_id')->nullable()->unique()->constrained('financial_artifacts')->restrictOnDelete();
            $table->uuid('artifact_reference')->unique();
            $table->uuid('operation_reference')->unique();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->string('payload_hash', 64);
            $table->string('snapshot_hash', 64);
            $table->longText('snapshot');
            $table->longText('manifest');
            $table->string('storage_path')->nullable();
            $table->string('artifact_hash', 64)->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('held')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Issued financial document evidence requires forward migrations.');
    }
};
