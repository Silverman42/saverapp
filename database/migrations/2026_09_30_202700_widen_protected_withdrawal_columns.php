<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (DB::table('withdrawal_requests')->orderBy('id')->cursor() as $row) {
            try {
                $destination = Crypt::decryptString($row->destination_reference);
            } catch (DecryptException $exception) {
                throw new RuntimeException('Withdrawal destination preflight failed; historical ciphertext must be recovered before migration.', previous: $exception);
            }
            if (trim($destination) === '' || ! mb_check_encoding($destination, 'UTF-8')) {
                throw new RuntimeException('The historical withdrawal destination cannot be proven.');
            }
        }
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->text('destination_reference')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Protected financial destination storage requires forward migrations.');
    }
};
