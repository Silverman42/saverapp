<?php

use App\Services\AuditProjection;
use App\Services\CashMethodCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('withdrawal_requests')->where('method', '!=', 'cash')->exists()) {
            throw new RuntimeException('Historical non-cash withdrawals require their own provable method contract.');
        }
        foreach (['cash_executions', 'cash_disbursements', 'withdrawal_requests'] as $table) {
            if (DB::table($table)->where('method_version', '!=', 1)->exists()) {
                throw new RuntimeException('Historical cash method versions require a provable immutable contract before migration.');
            }
        }
        Schema::create('cash_method_versions', function (Blueprint $table) {
            $table->id();
            $table->string('method_key', 30);
            $table->unsignedInteger('version');
            $table->json('contract');
            $table->char('contract_hash', 64);
            $table->timestamp('effective_at');
            $table->unique(['method_key', 'version']);
            $table->timestamps();
        });
        $contract = json_encode(CashMethodCatalogue::VERSION_ONE, JSON_THROW_ON_ERROR);
        DB::table('cash_method_versions')->insert(['method_key' => 'cash', 'version' => 1, 'contract' => $contract,
            'contract_hash' => AuditProjection::digest(CashMethodCatalogue::VERSION_ONE), 'effective_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Cash method contract history requires a forward migration.');
    }
};
