<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Additive prospective destination fence. Unconfigured legacy databases are
 * unchanged; existing source immutability and reversal guards stay installed.
 * An open period is necessary, not authorization for arbitrary direct SQL.
 */
final class FinancialEvidenceDestinationProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported financial evidence destination period fence engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $defs = [];
        $add = static function (string $name, string $table, string $event, string $date, string $applies, string $message)
            use (&$defs, $sqlite, $lock): void {
            $invalid = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'{$lock})
                AND (NOT EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                    AND s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
                    AND s.timezone = 'Asia/Aden' AND s.calendar_version = 1{$lock})
                OR NOT EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on AND p.state = 'OPEN'
                    AND NOT EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$lock})
                    AND NOT EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$lock}){$lock}))";
            $condition = preg_replace('/\s+/', ' ', trim("({$applies}) AND ({$invalid})"));
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $defs[$name] = [$table, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body, $event];
        };
        $local = static fn (string $stamp): string => $sqlite
            ? "date({$stamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$stamp}) + 10800) SECOND))";
        foreach ([
            'noncash_collection_claims' => ['created_at', 'updated_at', 'decided_at'],
            'late_receipt_reviews' => ['created_at', 'updated_at', 'decided_at'],
            'treasury_cash_counts' => ['observed_at', 'created_at'],
            'treasury_cash_count_reviews' => ['created_at'],
        ] as $table => $columns) {
            $message = str_starts_with($table, 'treasury_')
                ? 'Cash count evidence period is fenced' : 'Collection evidence period is fenced';
            foreach ($columns as $column) {
                $changed = $sqlite ? "OLD.{$column} IS NOT NEW.{$column}" : "NOT (OLD.{$column} <=> NEW.{$column})";
                $required = $column !== 'decided_at';
                $applies = $required ? '1 = 1' : "NEW.{$column} IS NOT NULL";
                $name = 'fin_ev_dest_'.$table.'_'.$column;
                $add($name.'_i', $table, 'INSERT', $local("NEW.{$column}"), $applies, $message);
                $add($name.'_u', $table, 'UPDATE', $local("NEW.{$column}"), "({$changed}) AND ({$applies})", $message);
            }
        }
        $revisionDate = "(SELECT ".$local('r.created_at')." FROM payment_allocation_revisions r WHERE r.id = NEW.revision_id{$lock})";
        foreach ([
            'payment_allocation_revisions' => $local('NEW.created_at'),
            'payment_allocations' => $revisionDate,
            'held_cash_shadow_reinstatements' => $revisionDate,
        ] as $table => $date) {
            $add('fin_ev_dest_'.$table.'_i', $table, 'INSERT', $date, '1 = 1', 'Allocation source period is fenced');
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body, $event] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            return $row !== null && $row->tbl_name === $table && $row->sql === "CREATE TRIGGER {$name} {$sql}";
        }
        $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
        return $row !== null && $row->EVENT_OBJECT_TABLE === $table && $row->EVENT_MANIPULATION === $event
            && $row->ACTION_TIMING === 'BEFORE' && preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) === $body;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        return true;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Financial evidence destination period protection incomplete.');
    }
}
