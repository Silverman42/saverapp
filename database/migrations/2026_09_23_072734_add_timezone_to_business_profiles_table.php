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
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('Africa/Lagos')->after('display_name');
        });

        DB::table('public_id_sequences')->insertOrIgnore([
            'entity_type' => 'plan',
            'prefix' => 'PLN-',
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('public_id_sequences')->where('entity_type', 'plan')->delete();

        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
