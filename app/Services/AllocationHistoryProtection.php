<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AllocationHistoryProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Unsupported allocation protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $body = $sqlite
            ? "BEGIN SELECT RAISE(ABORT, 'Allocation history is append-only'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation history is append-only'";
        $definitions = [];
        foreach ([
            'payment_allocation_revisions' => ['allocation_revision', ['subscriber_id', 'revision']],
            'payment_allocations' => ['allocation_row', ['revision_id', 'position']],
        ] as $table => [$prefix, $unique]) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $definitions[$prefix.'_reject_'.strtolower($event)] = "BEFORE {$event} ON {$table} {$body}";
            }
            if ($sqlite) {
                // SQLite REPLACE may delete a conflicting row without invoking
                // DELETE triggers. Cover both the primary and composite keys.
                [$first, $second] = $unique;
                $definitions[$prefix.'_reject_replace'] = "BEFORE INSERT ON {$table} FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM {$table} a WHERE a.id = NEW.id
                        OR (a.{$first} = NEW.{$first} AND a.{$second} = NEW.{$second})) {$body}";
            }
        }
        return $definitions;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) {
                DB::unprepared("CREATE TRIGGER {$name} {$definition}");
            }
        }
        if (! $this->active()) {
            throw new \RuntimeException('Allocation history protection missing after installation.');
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
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) return false;
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) return false;
        }
        return true;
    }
}
