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
        Schema::create('fee_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('fee_rule_id')->constrained('fee_rules')->restrictOnDelete();
            $table->unsignedInteger('fee_rule_version');
            $table->string('name', 100);
            $table->string('kind', 50)->default('registration');
            $table->string('model', 50);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('amount_kobo')->default(0);
            $table->string('customer_description', 500);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_profile_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_snapshots');
    }
};
