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
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->string('emergency_key_hash')->nullable();
            $table->timestamp('emergency_key_issued_at')->nullable();
            // Plain column: adding a foreign key would make SQLite rebuild the table and drop its singleton/immutability triggers.
            $table->unsignedBigInteger('emergency_admin_user_id')->nullable();
        });

        Schema::table('staff_recoveries', function (Blueprint $table): void {
            $table->string('kind', 20)->default('assisted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_recoveries', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn(['emergency_key_hash', 'emergency_key_issued_at', 'emergency_admin_user_id']);
        });
    }
};
