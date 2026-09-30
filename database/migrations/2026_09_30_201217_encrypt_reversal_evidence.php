<?php

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
        $rows = DB::table('reversal_requests')->orderBy('id')->get(['id', 'evidence_text']);
        foreach ($rows as $row) {
            if (! is_string($row->evidence_text) || ! mb_check_encoding($row->evidence_text, 'UTF-8')
                || trim($row->evidence_text) === '' || mb_strlen($row->evidence_text) > 1000) {
                throw new RuntimeException('Reversal evidence preflight failed; historical evidence must be reviewed before migration.');
            }
        }
        Schema::table('reversal_requests', function (Blueprint $table): void {
            $table->text('evidence_text')->change();
        });
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $current = DB::table('reversal_requests')->where('id', $row->id)->lockForUpdate()->sole();
                if ($current->evidence_text !== $row->evidence_text) {
                    throw new RuntimeException('Historical reversal evidence changed during migration.');
                }
                $encrypted = Crypt::encryptString($row->evidence_text);
                if (Crypt::decryptString($encrypted) !== $row->evidence_text) {
                    throw new RuntimeException('Historical reversal evidence could not be preserved.');
                }
                DB::table('reversal_requests')->where('id', $row->id)->update(['evidence_text' => $encrypted]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Protected financial evidence requires forward migrations.');
    }
};
