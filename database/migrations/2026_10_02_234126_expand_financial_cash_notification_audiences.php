<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_cash_events', function (Blueprint $table): void {
            $table->foreignId('audit_event_id')->nullable()->constrained('audit_events')->restrictOnDelete();
            $table->string('timezone', 64)->nullable();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
        });
        Schema::table('financial_cash_notification_intents', function (Blueprint $table): void {
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->index('financial_cash_event_id', 'financial_cash_notice_source_index');
        });
        Schema::table('financial_cash_notification_intents', function (Blueprint $table): void {
            $table->dropUnique('financial_cash_notice_once');
            $table->unique(['financial_cash_event_id', 'recipient_user_id', 'channel'], 'financial_cash_notice_channel_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial notification evidence requires forward migrations.');
    }
};
