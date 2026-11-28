<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Protect bank evidence at the database boundary in frozen or terminal periods.
 * Open-period content remains subject to service and provenance validation.
 */
final class BankEvidencePeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported bank evidence protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $freeze = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water' AND s.active_freeze_uuid IS NOT NULL{$current})";
        $range = static fn (string $from, string $to): string =>
            "EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                AND {$from} < p.next_on AND {$to} >= p.start_on AND (p.state <> 'OPEN'
                OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
        $statement = static fn (string $row): string => $range($row.'.from_date', $row.'.to_date');
        $line = static fn (string $row): string =>
            $range($row.'.booked_on', $row.'.booked_on')
            ." OR EXISTS (SELECT 1 FROM bank_statements b WHERE b.id = {$row}.bank_statement_id AND (".$statement('b')."){$current})";
        $match = static fn (string $row): string =>
            "EXISTS (SELECT 1 FROM bank_statement_lines l WHERE l.id = {$row}.bank_statement_line_id AND (".$line('l')."){$current})"
            ." OR EXISTS (SELECT 1 FROM treasury_postings t WHERE t.id = {$row}.treasury_posting_id AND (".$range('t.effective_on', 't.effective_on')."){$current})";
        $defs = [];
        $message = 'Bank evidence period is fenced';
        foreach ([
            'bank_statements' => [$statement, ['d.id = NEW.id', '(d.treasury_account_id = NEW.treasury_account_id AND d.reference = NEW.reference)']],
            'bank_statement_lines' => [$line, ['d.id = NEW.id', '(d.treasury_account_id = NEW.treasury_account_id AND d.bank_reference = NEW.bank_reference)']],
            'bank_statement_matches' => [$match, ['d.id = NEW.id', 'd.bank_statement_line_id = NEW.bank_statement_line_id', 'd.treasury_posting_id = NEW.treasury_posting_id']],
        ] as $table => [$fenced, $keys]) {
            $conditions = ['INSERT' => $fenced('NEW'), 'UPDATE' => '('.$fenced('OLD').') OR ('.$fenced('NEW').')',
                'DELETE' => $fenced('OLD')];
            if ($sqlite) {
                $collision = implode(' OR ', $keys);
                foreach (['INSERT', 'UPDATE'] as $event) {
                    $exclude = $event === 'UPDATE' ? ' AND d.id <> OLD.id' : '';
                    $conditions[$event] .= " OR EXISTS (SELECT 1 FROM {$table} d WHERE ({$collision}){$exclude} AND (".$fenced('d')."))";
                }
            }
            foreach ($conditions as $event => $condition) {
                $condition = preg_replace('/\s+/', ' ', trim("({$freeze}) OR ({$condition})"));
                $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                    : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
                $name = 'fin_bank_'.$table.'_'.strtolower($event);
                $defs[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
            }
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $event, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected bank evidence trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected bank evidence trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        // InnoDB parent referential actions bypass child triggers.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['bank_statements' => 2, 'bank_statement_lines' => 2, 'bank_statement_matches' => 3] as $table => $count) {
                $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                    ->where('TABLE_NAME', $table)->get();
                if ($rows->count() !== $count || $rows->contains(fn ($row) =>
                    ! in_array($row->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)
                    || ! in_array($row->UPDATE_RULE, ['RESTRICT', 'NO ACTION'], true))) return false;
            }
        }
        return true;
    }

    public function install(): void
    {
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Bank evidence protection incomplete.');
    }
}
