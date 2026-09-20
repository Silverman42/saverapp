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
        if (Schema::hasTable('sessions') && ! Schema::hasColumn('sessions', 'created_at')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->integer('created_at')->nullable()->after('last_activity');
            });
        }

        if (! Schema::hasTable('agent_trusted_devices')) {
            Schema::create('agent_trusted_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('device_token_hash', 64)->index();
                $table->string('device_name');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('trusted_until')->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_trusted_devices');

        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'created_at')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('created_at');
            });
        }
    }
};
