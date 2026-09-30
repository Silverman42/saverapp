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
        Schema::create('charge_category_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('publication_reference')->unique();
            $table->char('payload_hash', 64);
            $table->string('category_key', 80);
            $table->unsignedInteger('version');
            $table->string('kind', 30);
            $table->string('purpose', 500);
            $table->string('customer_description', 500);
            $table->string('destination_code', 64);
            $table->unsignedInteger('destination_mapping_version');
            $table->unsignedBigInteger('amount_kobo');
            $table->foreignId('fee_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['category_key', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Published financial categories require forward migrations.');
    }
};
