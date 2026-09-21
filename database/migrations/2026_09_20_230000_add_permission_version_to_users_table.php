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
        if (Schema::hasColumn('users', 'permission_version')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('permission_version')->default(1)->after('account_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'permission_version')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permission_version');
        });
    }
};
