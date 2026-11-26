<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Append-only close evidence and terminal period projection protection.
 * Not authorization for INSERT, proof of source coverage, or a DDL privilege fence.
 */
final class FinancialCloseJournalProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported close journal protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $definitions = [];
        $add = function (string $name, string $table, string $event, string $condition) use (&$definitions, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $message = 'Financial close evidence is immutable';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $definitions[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        foreach (['financial_close_commands' => 'close_cmd', 'financial_period_closures' => 'close_snapshot'] as $table => $prefix) {
            $add($prefix.'_reject_update', $table, 'UPDATE', '1 = 1');
            $add($prefix.'_reject_delete', $table, 'DELETE', '1 = 1');
        }
        $closed = "OLD.state = 'CLOSED'";
        $snapshot = 'EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = OLD.id)';
        // The coordinator inserts the approval and snapshot before updating the
        // FROZEN projection. Permit precisely that terminal projection update.
        $terminal = "OLD.state = 'FROZEN' AND NEW.state = 'CLOSED'
            AND NEW.id = OLD.id AND NEW.scope_key = OLD.scope_key AND NEW.month = OLD.month
            AND NEW.start_on = OLD.start_on AND NEW.next_on = OLD.next_on
            AND NEW.version = OLD.version + 1
            AND EXISTS (SELECT 1 FROM financial_period_closures c
                JOIN financial_close_commands j ON j.command_uuid = c.approval_uuid
                WHERE c.period_id = OLD.id AND j.period_id = OLD.id
                AND j.action = 'approve' AND j.result_version = NEW.version)";
        $add('close_period_reject_update', 'financial_periods', 'UPDATE',
            "({$closed}) OR (({$snapshot}) AND NOT COALESCE(({$terminal}), 0))");
        $add('close_period_reject_delete', 'financial_periods', 'DELETE', "({$closed}) OR ({$snapshot})");
        if ($sqlite) {
            // SQLite REPLACE can bypass DELETE triggers. Cover every unique key.
            $add('close_cmd_reject_replace', 'financial_close_commands', 'INSERT',
                'EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.command_uuid = NEW.command_uuid
                    OR (j.period_id = NEW.period_id AND j.result_version = NEW.result_version))');
            $add('close_snapshot_reject_replace', 'financial_period_closures', 'INSERT',
                'EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = NEW.period_id OR c.approval_uuid = NEW.approval_uuid)');
            $add('close_period_reject_replace', 'financial_periods', 'INSERT',
                "EXISTS (SELECT 1 FROM financial_periods p WHERE (p.id = NEW.id
                    OR (p.scope_key = NEW.scope_key AND p.month = NEW.month))
                    AND (p.state = 'CLOSED' OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id)))");
        }
        return $definitions;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $event, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected close protection trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected close protection trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        // MySQL foreign-key cascades do not run child triggers.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['financial_periods' => 1, 'financial_close_commands' => 1, 'financial_period_closures' => 2] as $table => $expected) {
                $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                    ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)->get();
                if ($rows->count() !== $expected || $rows->contains(fn ($row) =>
                    ! in_array($row->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)
                    || ! in_array($row->UPDATE_RULE, ['RESTRICT', 'NO ACTION'], true))) return false;
            }
        } elseif ((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            return false;
        }
        return true;
    }

    public function install(): void
    {
        // Verify every existing definition before creating any missing guard.
        // MySQL DDL commits implicitly; never partially install on a known mismatch.
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Financial close journal protection incomplete.');
    }
}
