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
        Schema::create('public_id_sequences', function (Blueprint $table): void {
            $table->string('entity_type', 50)->primary();
            $table->string('prefix', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        DB::table('public_id_sequences')->insert([
            [
                'entity_type' => 'customer',
                'prefix' => 'CUS-',
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'agent',
                'prefix' => 'AGT-',
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_id_sequences');
    }
};
