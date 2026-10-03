<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_notification_intents', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->text('rendered_snapshot')->nullable();
            $table->char('rendered_hash', 64)->nullable();
            $table->char('destination_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('plan_notification_intents')->whereNotNull('attempted_at')->exists()) {
            throw new RuntimeException('Retained plan mail history requires a forward migration.');
        }
        Schema::table('plan_notification_intents', function (Blueprint $table): void {
            $table->dropColumn(['attempt_count', 'attempted_at', 'template_version', 'rendered_snapshot', 'rendered_hash', 'destination_hash']);
        });
    }
};
