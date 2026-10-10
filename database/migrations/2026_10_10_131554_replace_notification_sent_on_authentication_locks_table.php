<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the unconditional notification flag with the recorded delivery status.
     * Existing locks only knew a notification was queued, so they keep that status.
     */
    public function up(): void
    {
        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->string('notification_status')->default('not_sent')->after('unlock_reason');
        });

        DB::table('authentication_locks')->where('notification_sent', true)->update(['notification_status' => 'queued']);

        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->dropColumn('notification_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->boolean('notification_sent')->default(false)->after('unlock_reason');
        });

        DB::table('authentication_locks')->where('notification_status', '!=', 'not_sent')->update(['notification_sent' => true]);

        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->dropColumn('notification_status');
        });
    }
};
