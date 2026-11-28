<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Protect cash count evidence at the database boundary in frozen or terminal periods.
 * Open-period content remains subject to service and provenance validation.
 */
final class CashCountPeriodProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported cash count evidence protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $freeze = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water' AND s.active_freeze_uuid IS NOT NULL{$current})";
        // The evidence is recorded in UTC; use the Aden accounting day.
        $date = static fn (string $timestamp): string => $sqlite
            ? "date({$timestamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$timestamp}) + 10800) SECOND))";
        $fence = static fn (string $day): string =>
            "EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                AND {$day} >= p.start_on AND {$day} < p.next_on AND (p.state <> 'OPEN'
                OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
        $count = static fn (string $row): string =>
            $fence($date($row.'.observed_at')).' OR '.$fence($date($row.'.created_at'));
        // A later review is evidence of its own period, never of the earlier cutoff.
        $review = static fn (string $row): string => $fence($date($row.'.created_at'));
        $defs = [];
        $message = 'Cash count evidence period is fenced';
        foreach ([
            'treasury_cash_counts' => [$count, ['d.id = NEW.id', 'd.reference = NEW.reference']],
            'treasury_cash_count_reviews' => [$review, ['d.id = NEW.id', 'd.treasury_cash_count_id = NEW.treasury_cash_count_id']],
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
                $name = 'fin_count_'.$table.'_'.strtolower($event);
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
                throw new \LogicException('Unexpected cash count evidence trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected cash count evidence trigger: '.$name);
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
            foreach (['treasury_cash_counts' => 2, 'treasury_cash_count_reviews' => 2] as $table => $count) {
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
        if (! $this->active()) throw new \LogicException('Cash count evidence protection incomplete.');
    }
}
