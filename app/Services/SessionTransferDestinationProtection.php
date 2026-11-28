<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Additive prospective destination fence. Unconfigured legacy databases are
 * unchanged; existing source immutability and reversal guards stay installed.
 * An open period is necessary, not authorization for arbitrary direct SQL.
 */
final class SessionTransferDestinationProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported session and transfer destination period fence engine.');
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
        $add('fin_session_dest_insert', 'cash_sessions', 'INSERT', 'DATE(NEW.session_date)', '1 = 1', 'Financial cash session evidence is fenced');
        $changed = $sqlite ? 'OLD.session_date IS NOT NEW.session_date' : 'NOT (OLD.session_date <=> NEW.session_date)';
        $add('fin_session_dest_update', 'cash_sessions', 'UPDATE', 'DATE(NEW.session_date)', $changed, 'Financial cash session evidence is fenced');
        // Transfer documents use their independent approval day, never the original receipt date.
        $add('fin_transfer_dest_insert', 'payment_account_transfers', 'INSERT',
            $local('NEW.created_at'), '1 = 1', 'Financial transfer period is fenced');
        $add('fin_transfer_audit_dest_insert', 'audit_events', 'INSERT',
            $local('NEW.occurred_at'), "NEW.event = 'payment.account_transferred'", 'Financial transfer period is fenced');
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
        if (! $this->active()) throw new \LogicException('Session and transfer destination period protection incomplete.');
    }
}
