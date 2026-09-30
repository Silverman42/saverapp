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
        Schema::create('collection_allocation_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_allocation_id')->unique()->constrained('collection_allocations')->restrictOnDelete();
            $table->foreignId('reversal_request_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Financial correction history requires a forward migration.');
    }
};
