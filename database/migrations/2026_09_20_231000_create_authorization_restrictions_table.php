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
        if (Schema::hasTable('authorization_restrictions')) {
            return;
        }

        Schema::create('authorization_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('restriction_type')->index();
            $table->string('permission_code')->nullable()->index();
            $table->string('source')->index();
            $table->string('source_reference')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable()->index();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('clear_reason')->nullable();
            $table->unsignedInteger('applied_permission_version');
            $table->unsignedInteger('cleared_permission_version')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'permission_code']);
            $table->index(['user_id', 'cleared_at', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorization_restrictions');
    }
};
