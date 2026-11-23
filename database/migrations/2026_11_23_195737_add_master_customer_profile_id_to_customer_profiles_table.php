<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A slave Customer profile points at its master profile; master profiles keep this column null.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->foreignId('master_customer_profile_id')
                ->nullable()
                ->after('user_id')
                ->constrained('customer_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('master_customer_profile_id');
        });
    }
};
