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
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_profile_id')->constrained('business_profiles')->restrictOnDelete();
            $table->string('timezone', 64);
            $table->date('month');
            $table->string('status', 16);
            $table->unsignedInteger('version');
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_profile_id', 'timezone', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_periods');
    }
};
