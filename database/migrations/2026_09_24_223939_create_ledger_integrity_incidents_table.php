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
        Schema::create('ledger_integrity_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('incident_reference')->unique();
            $table->string('category', 40);
            $table->string('status', 20);
            $table->string('summary', 255);
            $table->unsignedInteger('projection_version');
            $table->unsignedBigInteger('ledger_group_watermark');
            $table->timestamp('detected_at');
            $table->timestamps();
            $table->index(['status', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ledger_integrity_incidents')->exists()) {
            throw new RuntimeException('Ledger integrity incident evidence cannot be removed by rollback.');
        }
        Schema::dropIfExists('ledger_integrity_incidents');
    }
};
