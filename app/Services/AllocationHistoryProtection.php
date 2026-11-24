<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AllocationHistoryProtection
{
    public function install(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Unsupported allocation protection driver.');
        }
        foreach (['payment_allocation_revisions' => 'allocation_revision', 'payment_allocations' => 'allocation_row'] as $table => $prefix) {
            foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $event => $suffix) {
                $name = $prefix.'_reject_'.$suffix;
                if ($this->exists($name)) {
                    continue;
                }
                $body = $driver === 'sqlite'
                    ? "BEGIN SELECT RAISE(ABORT, 'Allocation history is append-only'); END"
                    : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation history is append-only'";
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}");
            }
        }
    }

    private function exists(string $name): bool
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()
            : DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->where('TRIGGER_NAME', $name)->exists();
    }

    public function active(): bool
    {
        foreach (['allocation_revision_reject_update', 'allocation_revision_reject_delete',
            'allocation_row_reject_update', 'allocation_row_reject_delete'] as $name) {
            if (! $this->exists($name)) {
                return false;
            }
        }
        return true;
    }
}
