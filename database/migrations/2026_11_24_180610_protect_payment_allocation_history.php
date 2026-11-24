<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new \RuntimeException('Unsupported allocation history protection driver.');
        }
        foreach (['payment_allocation_revisions' => 'allocation_revision', 'payment_allocations' => 'allocation_row'] as $table => $prefix) {
            foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $event => $suffix) {
                $name = $prefix.'_reject_'.$suffix;
                $body = $driver === 'sqlite'
                    ? "BEGIN SELECT RAISE(ABORT, 'Allocation history is append-only'); END"
                    : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation history is append-only'";
                $trigger = $driver === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}"
                    : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}";
                // Idempotent recovery if MySQL DDL was interrupted before the migration was recorded.
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                DB::unprepared($trigger);
            }
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Allocation history protection cannot be removed by rollback.');
    }
};
