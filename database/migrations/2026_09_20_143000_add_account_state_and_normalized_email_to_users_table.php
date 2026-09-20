<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Cannot add required account_state and email_normalized columns to users table: existing users found with unclassified account states.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email_normalized')->unique()->after('email');
            $table->string('account_state')->after('user_type');
            $table->timestamp('locked_until')->nullable()->after('account_state');
            $table->string('lock_category')->nullable()->after('locked_until');
            $table->string('lock_reason')->nullable()->after('lock_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_normalized',
                'account_state',
                'locked_until',
                'lock_category',
                'lock_reason',
            ]);
        });
    }
};
