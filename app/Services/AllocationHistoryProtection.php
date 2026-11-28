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
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition}");
        }
        if (! $this->active()) {
            throw new \RuntimeException('Allocation history protection missing after installation.');
        }
    }

    private function verified(string $name, string $definition): bool
    {
        if (! preg_match('/\ABEFORE (INSERT|UPDATE|DELETE) ON ([a-z_]+)(?: FOR EACH ROW)?\s+(.+)\z/s', $definition, $parts)) {
            throw new \LogicException('Invalid allocation trigger definition.');
        }
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            $valid = $row->tbl_name === $parts[2] && $row->sql === "CREATE TRIGGER {$name} {$definition}";
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', trim($sql));
            $valid = $row->EVENT_OBJECT_TABLE === $parts[2] && $row->EVENT_MANIPULATION === $parts[1]
                && $row->ACTION_TIMING === 'BEFORE' && $normalize($row->ACTION_STATEMENT) === $normalize($parts[3]);
        }
        if (! $valid) throw new \LogicException('Unexpected allocation history protection: '.$name);
        return true;
    }

    public function active(): bool
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) return false;
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        return true;
    }
}
