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
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('deduction_amount_kobo')->default(0)->after('fee_amount_kobo');
            $table->foreignId('deduction_category_version_id')->nullable()->after('deduction_amount_kobo')
                ->constrained('charge_category_versions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Immutable withdrawal terms require a forward migration.');
    }
};
