<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_charge_notification_intents', function (Blueprint $table): void {
            $table->foreignId('agent_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('customer_assignments')->restrictOnDelete();
            $table->index('manual_charge_id', 'charge_notice_source_index');
        });
        Schema::table('manual_charge_notification_intents', function (Blueprint $table): void {
            $table->dropUnique('manual_charge_notice_once');
            $table->unique(['manual_charge_id', 'recipient_user_id', 'channel'], 'manual_charge_notice_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retained charge delivery evidence requires forward migrations.');
    }
};
