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
        Schema::create('financial_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_profile_id')->constrained()->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->string('type', 16);
            $table->string('title', 150);
            $table->unsignedBigInteger('amount_kobo');
            $table->date('note_date');
            $table->string('category', 80)->nullable();
            $table->text('body');
            $table->string('status', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['business_profile_id', 'status', 'note_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_notes');
    }
};
