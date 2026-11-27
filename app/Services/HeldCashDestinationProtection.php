<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Additive prospective destination fence. Unconfigured legacy databases are
 * unchanged; existing source immutability and reversal guards stay installed.
 * An open period is necessary, not authorization for arbitrary direct SQL.
 */
final class HeldCashDestinationProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported held cash destination period fence engine.');
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
            'treasury_held_cash_assignments' => 'NEW.effective_on',
            'treasury_held_cash_payout_authorizations' => 'NEW.effective_on',
            'treasury_held_cash_payout_cancellations' => 'NEW.effective_on',
            'treasury_held_cash_payouts' => 'NEW.effective_on',
            'treasury_held_cash_credits' => 'NEW.effective_on',
            'treasury_held_cash_liabilities' => "(SELECT o.effective_on FROM financial_posting_operations o
                WHERE o.operation_uuid = NEW.operation_uuid AND o.scope_key = 'water'{$lock})",
        ] as $table => $date) {
            $add('fin_held_dest_'.$table.'_i', $table, 'INSERT', $date, '1 = 1', 'Held cash source period is fenced');
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
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if ($this->verified($name, $definition)) continue;
            $exists = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()
                : FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->isNotEmpty();
            if ($exists) throw new \LogicException('Unexpected HeldCashDestinationProtection trigger: '.$name);
            $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Held cash destination period protection incomplete.');
    }
}
