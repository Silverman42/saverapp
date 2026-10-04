<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The production engine refuses every UPDATE and DELETE on posted ledger rows, whoever issues them. The model hooks already guard
     * Eloquent; this stops query-builder and manual statements too. SQLite test databases keep only the model hooks because the suite
     * deliberately damages rows to prove that readers fail closed.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::unprepared("CREATE TRIGGER ledger_posting_groups_no_update BEFORE UPDATE ON ledger_posting_groups FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger rows are immutable'");
        DB::unprepared("CREATE TRIGGER ledger_posting_groups_no_delete BEFORE DELETE ON ledger_posting_groups FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger rows are immutable'");
        DB::unprepared("CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger rows are immutable'");
        DB::unprepared("CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger rows are immutable'");
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger immutability requires a forward migration.');
    }
};
