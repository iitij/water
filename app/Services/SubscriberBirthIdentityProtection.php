<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Preserve recorded subscriber birth identity used by prospective cutoff replay.
 * This does not authenticate the original birth record or certify all DML. */
final class SubscriberBirthIdentityProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported subscriber birth identity engine.');
        }
        $sqlite = $driver === 'sqlite';
        $changed = $sqlite ? 'OLD.id IS NOT NEW.id OR OLD.created_at IS NOT NEW.created_at'
            : 'NOT (OLD.id <=> NEW.id) OR NOT (OLD.created_at <=> NEW.created_at)';
        $scope = "EXISTS (SELECT 1 FROM financial_scopes WHERE scope_key = 'water')";
        $message = 'Subscriber birth identity is immutable';
        $condition = "({$changed}) AND {$scope}";
        $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF locked_scope IS NOT NULL AND ({$changed}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        $defs = ['fin_subscriber_birth_update' => ['subscribers', 'UPDATE',
            "BEFORE UPDATE ON subscribers FOR EACH ROW {$body}", $body]];
        if ($sqlite) {
            $body = "WHEN {$scope} AND EXISTS (SELECT 1 FROM subscribers WHERE id = NEW.id) BEGIN SELECT RAISE(ABORT, '{$message}'); END";
            $defs['fin_subscriber_birth_replace'] = ['subscribers', 'INSERT',
                "BEFORE INSERT ON subscribers FOR EACH ROW {$body}", $body];
        }
        // A missing/invalid creation timestamp would otherwise disappear
        // from cutoff queries. Keep historical rows untouched; reject new ones.
        $invalid = $sqlite ? "NEW.created_at IS NULL OR strftime('%Y-%m-%d %H:%M:%S', NEW.created_at) IS NULL"
            : 'NEW.created_at IS NULL OR YEAR(NEW.created_at) = 0';
        $message = 'Subscriber birth timestamp is required';
        $body = $sqlite ? "WHEN {$scope} AND ({$invalid}) BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF locked_scope IS NOT NULL AND ({$invalid}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        $defs['fin_subscriber_birth_insert'] = ['subscribers', 'INSERT',
            "BEFORE INSERT ON subscribers FOR EACH ROW {$body}", $body];
        // SQLite stores timestamps as text. A parseable but noncanonical
        // value (for example ISO 8601 with T/Z) can cross a lexical cutoff.
        // Keep the original required-timestamp trigger unchanged on upgrade.
        if ($sqlite) {
            $canonical = "strftime('%Y-%m-%d %H:%M:%S', NEW.created_at, '+0 seconds')";
            $condition = "{$scope} AND NEW.created_at IS NOT NULL AND NEW.created_at IS NOT {$canonical}";
            $body = "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Subscriber birth timestamp must be canonical UTC'); END";
            $defs['fin_subscriber_birth_canonical'] = ['subscribers', 'INSERT',
                "BEFORE INSERT ON subscribers FOR EACH ROW {$body}", $body];
        }
        // Preserve account identity even when no posting exists. InnoDB
        // cascades bypass child triggers, so also protect the square parent.
        // Let the existing freeze guard own the error during a real freeze.
        $configured = "EXISTS (SELECT 1 FROM financial_scopes WHERE scope_key = 'water' AND active_freeze_uuid IS NULL";
        $configured .= $sqlite ? ')' : ' FOR UPDATE)';
        foreach (['subscribers', 'squares'] as $table) {
            $linked = $table === 'subscribers' ? '1 = 1'
                : 'EXISTS (SELECT 1 FROM subscribers WHERE square_id = OLD.id'.($sqlite ? '' : ' FOR UPDATE').')';
            $condition = "{$configured} AND ({$linked})";
            $message = 'Financial source parent graph is fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $defs['fin_birth_'.$table.'_delete'] = [$table, 'DELETE', "BEFORE DELETE ON {$table} FOR EACH ROW {$body}", $body];
        }
        if ($sqlite) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $exclude = $event === 'UPDATE' ? ' AND victim.id <> OLD.id' : '';
                $collision = "EXISTS (SELECT 1 FROM squares victim WHERE (victim.id = NEW.id OR victim.code = NEW.code){$exclude} AND EXISTS (SELECT 1 FROM subscribers WHERE square_id = victim.id))";
                $body = "WHEN {$configured} AND {$collision} BEGIN SELECT RAISE(ABORT, 'Financial source parent replacement is fenced'); END";
                $defs['fin_birth_squares_replace_'.strtolower($event)] = ['squares', $event,
                    "BEFORE {$event} ON squares FOR EACH ROW {$body}", $body];
            }
        }
        $deleted = $sqlite ? 'OLD.deleted_at IS NOT NEW.deleted_at' : 'NOT (OLD.deleted_at <=> NEW.deleted_at)';
        $history = 'EXISTS (SELECT 1 FROM transactions WHERE subscriber_id = OLD.id'.($sqlite ? '' : ' FOR UPDATE').')';
        $condition = "{$configured} AND ({$deleted}) AND {$history}";
        $message = 'Subscriber with ledger history cannot change deletion state';
        $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        $defs['fin_birth_subscriber_retire'] = ['subscribers', 'UPDATE',
            "BEFORE UPDATE ON subscribers FOR EACH ROW {$body}", $body];
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $event, $sql, $body] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected subscriber birth identity trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected subscriber birth identity trigger: '.$name);
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
        $missing = [];
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) $missing[$name] = $definition;
        }
        foreach ($missing as $name => $definition) {
            DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
        }
        if (! $this->active()) throw new \LogicException('Subscriber birth identity protection incomplete.');
    }
}
