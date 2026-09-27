<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_mail_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_family', 30);
            $table->unsignedBigInteger('owner_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('dispatch_count')->default(0);
            $table->timestamp('last_dispatch_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_family', 'owner_id'], 'management_mail_dispatch_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_mail_dispatches');
    }
};
