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
        Schema::create('fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('name', 100);
            $table->string('kind', 50)->default('registration');
            $table->string('model', 50);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('amount_kobo')->default(0);
            $table->string('customer_description', 500);
            $table->timestamp('effective_at');
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('publication_reason', 500);
            $table->timestamps();

            $table->unique(['kind', 'version']);
            $table->index(['kind', 'effective_at', 'retired_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_rules');
    }
};
