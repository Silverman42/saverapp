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
        if (Schema::hasColumn('authentication_locks', 'unlock_verification_method')) {
            return;
        }

        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->string('unlock_verification_method')->nullable()->after('unlock_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('authentication_locks', 'unlock_verification_method')) {
            return;
        }

        Schema::table('authentication_locks', function (Blueprint $table) {
            $table->dropColumn('unlock_verification_method');
        });
    }
};
