<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Protect persisted registry evidence and reject inserts into fenced periods.
 * These triggers do not authenticate arbitrary SQL or prove business coverage.
 */
final class FinancialPostingRegistryProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported posting registry driver.');
        }
        $sqlite = $driver === 'sqlite';
        $definitions = [];
        $add = static function (string $name, string $table, string $event, string $condition) use (&$definitions, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $message = 'Financial registry evidence is immutable or fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $definitions[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        foreach (['financial_posting_operations' => 'fin_registry_op', 'financial_posting_events' => 'fin_registry_leg'] as $table => $prefix) {
            $add($prefix.'_reject_update', $table, 'UPDATE', '1 = 1');
            $add($prefix.'_reject_delete', $table, 'DELETE', '1 = 1');
        }
        $open = "s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
            AND s.timezone = 'Asia/Aden' AND s.calendar_version = 1
            AND p.state = 'OPEN' AND p.scope_key = s.scope_key
            AND NOT EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve')";
        $operation = "NOT EXISTS (SELECT 1 FROM financial_periods p JOIN financial_scopes s ON s.scope_key = p.scope_key
            WHERE p.id = NEW.period_id AND s.scope_key = NEW.scope_key AND {$open}
            AND s.fence_epoch = NEW.fence_epoch AND p.version = NEW.period_version
            AND NEW.effective_on >= p.start_on AND NEW.effective_on < p.next_on)";
        $event = "NOT EXISTS (SELECT 1 FROM financial_posting_operations o
            JOIN financial_periods p ON p.id = o.period_id JOIN financial_scopes s ON s.scope_key = p.scope_key
            WHERE o.operation_uuid = NEW.operation_uuid AND o.scope_key = NEW.scope_key
            AND s.scope_key = NEW.scope_key AND {$open}
            AND s.fence_epoch = o.fence_epoch AND p.version = o.period_version
            AND o.effective_on >= p.start_on AND o.effective_on < p.next_on
            AND NEW.currency = s.currency)";
        if ($sqlite) {
            // REPLACE can suppress DELETE triggers, including when an alternative
            // unique source identity is used.
            $operation .= ' OR EXISTS (SELECT 1 FROM financial_posting_operations o WHERE o.operation_uuid = NEW.operation_uuid)';
            $event .= ' OR EXISTS (SELECT 1 FROM financial_posting_events e WHERE e.id = NEW.id
                OR (e.scope_key = NEW.scope_key AND e.source_type = NEW.source_type AND e.source_id = NEW.source_id AND e.leg = NEW.leg))';
        }
        $add('fin_registry_op_reject_insert', 'financial_posting_operations', 'INSERT', $operation);
        $add('fin_registry_leg_reject_insert', 'financial_posting_events', 'INSERT', $event);
        return $definitions;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $event, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected posting registry protection trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected posting registry protection trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        if (DB::getDriverName() === 'sqlite') {
            return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1;
        }
        foreach (['financial_posting_operations', 'financial_posting_events'] as $table) {
            $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)->get();
            if ($rows->count() !== 2 || $rows->contains(fn ($row) =>
                ! in_array($row->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)
                || ! in_array($row->UPDATE_RULE, ['RESTRICT', 'NO ACTION'], true))) return false;
        }
        return true;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Posting registry protection incomplete.');
    }
}
