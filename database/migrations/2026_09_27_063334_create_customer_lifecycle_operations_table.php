<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_lifecycle_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_reference')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->string('payload_hash', 64);
            $table->unsignedInteger('committed_version');
            $table->foreignId('customer_status_history_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['customer_profile_id', 'actor_user_id'], 'customer_lifecycle_actor_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('customer_lifecycle_operations')->exists()) {
            throw new RuntimeException('Retained lifecycle evidence requires a reviewed forward migration.');
        }
        Schema::dropIfExists('customer_lifecycle_operations');
    }
};
