<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_notification_intents', function (Blueprint $table): void {
            $table->string('channel', 20)->default('database');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->text('rendered_snapshot')->nullable();
            $table->char('rendered_hash', 64)->nullable();
            $table->char('destination_hash', 64)->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->unique(['collection_receipt_id', 'recipient_user_id', 'channel'], 'collection_notice_channel_once');
        });
        Schema::table('collection_notification_intents', function (Blueprint $table): void {
            $table->dropUnique('collection_notice_recipient_once');
        });
    }

    public function down(): void
    {
        if (DB::table('collection_notification_intents')->where('channel', 'mail')->exists()) {
            throw new RuntimeException('Retained receipt mail history requires a forward migration.');
        }
        Schema::table('collection_notification_intents', function (Blueprint $table): void {
            $table->unique(['collection_receipt_id', 'recipient_user_id'], 'collection_notice_recipient_once');
            $table->dropUnique('collection_notice_channel_once');
            $table->dropColumn(['channel', 'attempt_count', 'attempted_at', 'template_version', 'rendered_snapshot', 'rendered_hash', 'destination_hash', 'failure_reason']);
        });
    }
};
