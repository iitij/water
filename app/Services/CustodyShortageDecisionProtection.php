<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Append-only shortage classification documents; does not authorize inserts. */
final class CustodyShortageDecisionProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported financial approval audit engine.');
        }
        $sqlite = $driver === 'sqlite';
        $types = [
            'financial.custody_shortage_loss_proposed' => 'custody_shortage_loss',
            'financial.custody_shortage_loss_approved' => 'custody_shortage_loss',
        ];
        $events = implode(', ', array_map(fn ($event) => "'{$event}'", array_keys($types)));
        $scope = static fn (string $a): string => "{$a}.event IN ({$events})";
        $identity = [];
        foreach ($types as $event => $type) {
            $hex = strtoupper(bin2hex($type));
            $identity[] = "(NEW.event = '{$event}' AND HEX(NEW.entity_type) = '{$hex}')";
        }
        $valid = '('.implode(' OR ', $identity).') AND NEW.actor_user_id IS NOT NULL AND NEW.entity_id IS NOT NULL'
            ." AND LENGTH(TRIM(NEW.entity_id)) > 0 AND NEW.source = 'internal'";
        $duplicate = 'EXISTS (SELECT 1 FROM audit_events a WHERE a.event = NEW.event AND a.entity_id = NEW.entity_id)';
        $defs = [];
        $add = function (string $name, string $table, string $event, string $condition) use (&$defs, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $message = 'Custody shortage audit is immutable or invalid';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $defs[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        $add('fin_shortage_audit_update', 'audit_events', 'UPDATE', "({$scope('OLD')}) OR ({$scope('NEW')})");
        $add('fin_shortage_audit_delete', 'audit_events', 'DELETE', $scope('OLD'));
        $insert = "({$scope('NEW')}) AND (NOT COALESCE(({$valid}), 0) OR {$duplicate})";
        if ($sqlite) {
            $insert = "({$insert}) OR EXISTS (SELECT 1 FROM audit_events a WHERE a.id = NEW.id AND {$scope('a')})";
        }
        $add('fin_shortage_audit_insert', 'audit_events', 'INSERT', $insert);
        // MySQL ON DELETE SET NULL does not execute child triggers.
        $referenced = "EXISTS (SELECT 1 FROM audit_events a WHERE a.actor_user_id = OLD.id AND {$scope('a')})";
        $add('fin_shortage_actor_delete', 'users', 'DELETE', $referenced);
        $add('fin_shortage_actor_update', 'users', 'UPDATE', "OLD.id <> NEW.id AND {$referenced}");
        if ($sqlite) {
            $add('fin_shortage_actor_replace', 'users', 'INSERT',
                "EXISTS (SELECT 1 FROM users u JOIN audit_events a ON a.actor_user_id = u.id
                    WHERE (u.id = NEW.id OR u.email = NEW.email) AND {$scope('a')})");
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
                throw new \LogicException('Unexpected custody shortage trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected custody shortage trigger: '.$name);
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
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Custody shortage audit protection incomplete.');
    }
}
