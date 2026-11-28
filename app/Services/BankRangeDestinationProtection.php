<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Every day of a bank statement must be covered by contiguous canonical
 * open months, once the financial scope is configured. Existing period
 * immutability and source identity checks remain independently applicable.
 */
final class BankRangeDestinationProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported bank range destination engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $canonical = $sqlite
            ? "p.start_on = p.month || '-01' AND p.next_on = date(p.start_on, '+1 month')"
            : "p.start_on = CONCAT(p.month, '-01') AND p.next_on = DATE_ADD(p.start_on, INTERVAL 1 MONTH)";
        $range = static function (string $from, string $to) use ($lock, $canonical): string {
            return "{$from} IS NOT NULL AND {$to} IS NOT NULL AND {$from} <= {$to}
                AND EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$from} >= p.start_on AND {$from} < p.next_on{$lock})
                AND EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$to} >= p.start_on AND {$to} < p.next_on{$lock})
                AND NOT EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$from} < p.next_on AND {$to} >= p.start_on
                    AND (NOT COALESCE(({$canonical}), 0) OR p.state <> 'OPEN'
                        OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$lock})
                        OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$lock})
                        OR (p.next_on <= {$to} AND NOT EXISTS (SELECT 1 FROM financial_periods n
                            WHERE n.scope_key = 'water' AND n.start_on = p.next_on AND n.next_on > n.start_on{$lock}))){$lock})";
        };
        $scope = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'{$lock})";
        $active = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
            AND s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
            AND s.timezone = 'Asia/Aden' AND s.calendar_version = 1{$lock})";
        $statement = $range('NEW.from_date', 'NEW.to_date');
        $line = "EXISTS (SELECT 1 FROM bank_statements b WHERE b.id = NEW.bank_statement_id
            AND b.treasury_account_id = NEW.treasury_account_id
            AND NEW.booked_on >= b.from_date AND NEW.booked_on <= b.to_date
            AND (".$range('b.from_date', 'b.to_date')."){$lock})";
        $match = "EXISTS (SELECT 1 FROM bank_statement_lines l JOIN bank_statements b ON b.id = l.bank_statement_id
            JOIN treasury_postings t ON t.id = NEW.treasury_posting_id
            WHERE l.id = NEW.bank_statement_line_id
            AND l.treasury_account_id = b.treasury_account_id
            AND l.booked_on >= b.from_date AND l.booked_on <= b.to_date
            AND t.effective_on >= b.from_date AND t.effective_on <= b.to_date
            AND (".$range('b.from_date', 'b.to_date')."){$lock})";
        $defs = [];
        $message = 'Bank evidence period is fenced';
        foreach (['bank_statements' => $statement, 'bank_statement_lines' => $line, 'bank_statement_matches' => $match] as $table => $valid) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $condition = preg_replace('/\s+/', ' ', trim("({$scope}) AND (NOT ({$active}) OR NOT COALESCE(({$valid}), 0))"));
                $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                    : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
                $defs['fin_bank_range_'.$table.'_'.strtolower($event)] = [$table, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body, $event];
            }
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
        if (! $this->active()) throw new \LogicException('Bank range destination protection incomplete.');
    }
}
