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
        if (DB::table('financial_artifacts')->whereNotIn('status', ['queued', 'failed', 'ready', 'cancelled', 'expired'])->exists()) {
            throw new RuntimeException('Resolve unprovable historical render outcomes before introducing attempt generations.');
        }
        Schema::table('financial_artifacts', function (Blueprint $table) {
            $table->unsignedInteger('render_generation')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Financial render attempt generations must be preserved by a forward migration.');
    }
};
