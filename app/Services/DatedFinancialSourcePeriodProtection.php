<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Additive prospective destination fence. Unconfigured legacy databases are
 * unchanged; existing source immutability and reversal guards stay installed.
 * An open period is necessary, not authorization for arbitrary direct SQL.
 */
final class DatedFinancialSourcePeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported dated source period fence engine.');
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
        foreach ([
            'invoices' => 'bill_date', 'payments' => 'payment_date', 'payment_refunds' => 'disbursed_on',
            'treasury_accounts' => 'opening_on', 'treasury_postings' => 'effective_on',
            'cash_handovers' => 'received_on', 'cash_session_fundings' => 'issued_on',
            'treasury_transfers' => 'dispatched_on', 'treasury_transfer_receipts' => 'received_on',
            'treasury_bank_fees' => 'charged_on', 'treasury_cash_adjustments' => 'adjusted_on',
            'treasury_bank_collections' => 'cleared_on', 'treasury_late_cash_receipts' => 'received_on',
        ] as $table => $column) {
            $changed = $sqlite ? "OLD.{$column} IS NOT NEW.{$column}" : "NOT (OLD.{$column} <=> NEW.{$column})";
            $date = "DATE(NEW.{$column})";
            $add('fin_dated_'.$table.'_insert', $table, 'INSERT', $date, '1 = 1', 'Financial source period is fenced');
            $add('fin_dated_'.$table.'_update', $table, 'UPDATE', $date, $changed, 'Financial source mutation is fenced');
            if (in_array($table, ['invoices', 'payments'], true)) {
                $local = $sqlite ? "date(NEW.voided_at, '+3 hours')"
                    : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP(NEW.voided_at) + 10800) SECOND))";
                $voidChanged = $sqlite ? 'OLD.voided_at IS NOT NEW.voided_at' : 'NOT (OLD.voided_at <=> NEW.voided_at)';
                $add('fin_dated_'.$table.'_void_insert', $table, 'INSERT', $local, 'NEW.voided_at IS NOT NULL', 'Financial source reversal date is fenced');
                $add('fin_dated_'.$table.'_void_update', $table, 'UPDATE', $local,
                    "NEW.voided_at IS NOT NULL AND ({$voidChanged})", 'Financial source reversal date is fenced');
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
        if (! $this->active()) throw new \LogicException('Dated source period protection incomplete.');
    }
}
