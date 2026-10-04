<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fee obligation history (canonical audit already has append-only triggers) and reservation amounts, cannot be edited or removed by manual statements.
     * Corrections go through the owning workflow. SQLite keeps the model hooks so tests can damage rows to prove readers fail closed.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['fee_obligation_entries'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_no_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Canonical history is immutable'");
            }
        }
        DB::unprepared("CREATE TRIGGER withdrawal_reservations_no_delete BEFORE DELETE ON withdrawal_reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservations are released through the owning workflow'");
        DB::unprepared("CREATE TRIGGER withdrawal_reservations_fixed_amount BEFORE UPDATE ON withdrawal_reservations FOR EACH ROW BEGIN IF NEW.gross_amount_kobo <> OLD.gross_amount_kobo OR NEW.customer_profile_id <> OLD.customer_profile_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservation amounts are immutable'; END IF; END");
    }

    public function down(): void
    {
        throw new RuntimeException('Correction boundaries require a forward migration.');
    }
};
