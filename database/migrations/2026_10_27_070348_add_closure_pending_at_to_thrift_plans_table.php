<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thrift_plans', function (Blueprint $table) {
            $table->timestamp('closure_pending_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('thrift_plans', function (Blueprint $table) {
            $table->dropIndex(['closure_pending_at']);
            $table->dropColumn('closure_pending_at');
        });
    }
};
