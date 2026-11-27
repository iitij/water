<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** New shortage documents must be issued within an active, open financial
 * period. Existing immutable audit guards still protect their identity.
 * This fence is not an authorization for arbitrary open-period DML. */
final class CustodyShortagePeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported shortage period fence engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $local = $sqlite ? "date(NEW.occurred_at, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP(NEW.occurred_at) + 10800) SECOND))";
        $events = "NEW.event IN ('financial.custody_shortage_loss_proposed', 'financial.custody_shortage_loss_approved', 'financial.custody_shortage_recovery_proposed')";
        $defs = [];
        foreach ([
            'fin_shortage_audit_period_insert' => ['audit_events', $events, $local],
            'fin_shortage_recovery_period_insert' => ['custody_shortage_recoveries', '1 = 1', 'NEW.effective_on'],
        ] as $name => [$table, $applies, $date]) {
            $invalid = "NOT EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                AND s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
                AND s.timezone = 'Asia/Aden'{$lock})
                OR NOT EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on AND p.state = 'OPEN'
                    AND NOT EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$lock})
                    AND NOT EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$lock}){$lock})";
            $condition = preg_replace('/\s+/', ' ', trim("({$applies}) AND ({$invalid})"));
            $message = 'Custody shortage source period is fenced';
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; IF {$applies} THEN SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END IF; END";
            $body = preg_replace('/\s+/', ' ', trim($body));
            $defs[$name] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body];
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            return $row !== null && $row->tbl_name === $table && $row->sql === "CREATE TRIGGER {$name} {$sql}";
        }
        $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
        return $row !== null && $row->EVENT_OBJECT_TABLE === $table && $row->EVENT_MANIPULATION === 'INSERT'
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
        if (! $this->active()) throw new \LogicException('Custody shortage period protection incomplete.');
    }
}
