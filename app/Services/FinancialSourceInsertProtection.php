<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Prospective period fence for dated financial source INSERTs. Does not
 * authorize arbitrary open-period writes, certify historical rows, or fence
 * all UPDATE/DELETE paths. An uninitialized legacy scope remains inert.
 */
final class FinancialSourceInsertProtection
{
    private const DATES = [
        'invoices' => 'bill_date',
        'payments' => 'payment_date',
        'payment_refunds' => 'disbursed_on',
        'treasury_accounts' => 'opening_on',
        'treasury_postings' => 'effective_on',
    ];

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported financial source fence driver.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $defs = [];
        foreach (self::DATES as $table => $column) {
            $date = "NEW.{$column}";
            $invalid = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                AND s.active_freeze_uuid IS NOT NULL{$current})
                OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on
                    AND (p.state <> 'OPEN'
                        OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                        OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
            $invalid = preg_replace('/\s+/', ' ', trim($invalid));
            $message = 'Financial source period is fenced';
            $body = $sqlite ? "WHEN {$invalid} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $name = 'fin_source_'.$table.'_insert';
            $defs[$name] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body];
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected source insert fence: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== 'INSERT'
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected source insert fence: '.$name);
            }
        }
        return true;
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
        if (! $this->active()) throw new \LogicException('Financial source insert protection incomplete.');
    }
}
