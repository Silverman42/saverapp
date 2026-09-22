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
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('business_id', 30)->unique();
            $table->string('display_name', 150)->default('SaverApp');
            $table->string('legal_name', 200)->nullable();
            $table->string('support_email', 254)->nullable();
            $table->string('support_phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('invitation_sender_email', 254)->nullable();
            $table->string('invitation_sender_name', 150)->nullable();
            $table->boolean('is_invitation_sender_verified')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        DB::table('business_profiles')->insert([
            'business_id' => 'BUS-000001',
            'display_name' => 'SaverApp',
            'legal_name' => 'SaverApp Ltd',
            'is_invitation_sender_verified' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('public_id_sequences')) {
            DB::table('public_id_sequences')->updateOrInsert(
                ['entity_type' => 'business'],
                [
                    'prefix' => 'BUS-',
                    'next_number' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
