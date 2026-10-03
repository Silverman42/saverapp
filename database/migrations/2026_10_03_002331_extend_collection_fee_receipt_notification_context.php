<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_notification_intents', function (Blueprint $table): void {
            $table->string('audience_type', 30)->default('subject_customer');
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->text('context_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retained collection fee receipt evidence requires forward migrations.');
    }
};
