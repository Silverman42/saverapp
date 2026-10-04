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
        Schema::create('customer_payout_destinations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('destination_reference')->unique();
            $table->uuid('registration_reference')->unique();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unique(['customer_profile_id', 'version']);
            $table->foreignId('active_customer_profile_id')->nullable()->unique()->constrained('customer_profiles')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('provider_key', 32);
            $table->string('bank_code', 10);
            $table->string('bank_name', 100);
            $table->text('account_token');
            $table->char('account_fingerprint', 64)->index();
            $table->string('account_mask', 20);
            $table->text('verified_payee_name');
            $table->string('name_match', 12);
            $table->text('registration_attestation');
            $table->foreignId('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->foreignId('customer_payout_destination_id')->nullable()->after('destination_mask')
                ->constrained('customer_payout_destinations')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Payout destination history requires a forward migration.');
    }
};
