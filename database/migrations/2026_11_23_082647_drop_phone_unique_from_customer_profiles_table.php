<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers may share a phone number (for example a family contact), so the normalized phone
     * keeps a plain index for directory search instead of a unique one.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropUnique(['phone_normalized']);
            $table->index('phone_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropIndex(['phone_normalized']);
            $table->unique('phone_normalized');
        });
    }
};
