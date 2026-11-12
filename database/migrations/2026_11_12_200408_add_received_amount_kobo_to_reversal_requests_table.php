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
        Schema::table('reversal_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('received_amount_kobo')->nullable()->after('original_amount_kobo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reversal_requests', function (Blueprint $table) {
            $table->dropColumn('received_amount_kobo');
        });
    }
};
