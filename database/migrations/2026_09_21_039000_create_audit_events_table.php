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
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 100)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 50)->nullable();
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_reference', 100)->nullable()->index();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
