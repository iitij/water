<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class HeldCashReleaseDocumentProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported release proposal protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $body = $sqlite
            ? "BEGIN SELECT RAISE(ABORT, 'Held release proposal is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held release proposal is immutable'";
        $definitions = [];
        foreach ([
            'held_cash_release_proposals' => ['release_plan', ['operation_uuid', 'reference']],
            'held_cash_release_withdrawals' => ['release_withdraw', ['proposal_uuid']],
        ] as $table => [$prefix, $keys]) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $definitions[$prefix.'_reject_'.strtolower($event)] = "BEFORE {$event} ON {$table} {$body}";
            }
            if ($sqlite) {
                $conflict = implode(' OR ', array_map(fn ($key) => "d.{$key} = NEW.{$key}", $keys));
                $definitions[$prefix.'_reject_replace'] = "BEFORE INSERT ON {$table} FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM {$table} d WHERE {$conflict}) {$body}";
            }
        }
        return $definitions;
    }

    private function exists(string $name): bool
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()
            : DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('TRIGGER_NAME', $name)->exists();
    }

    public function active(): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return false;
        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
        foreach ($this->definitions() as $name => $definition) {
            if (! preg_match('/\ABEFORE (UPDATE|DELETE|INSERT) ON ([a-z_]+) (.*)\z/s', $definition, $parts)) return false;
            [, $event, $table, $body] = $parts;
            if ($driver === 'sqlite') {
                $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)
                    ->where('tbl_name', $table)->first();
                if ($trigger === null || $normalize($trigger->sql) !== $normalize("CREATE TRIGGER {$name} {$definition}")) return false;
            } else {
                $trigger = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                    ->where('TRIGGER_NAME', $name)->where('EVENT_OBJECT_TABLE', $table)
                    ->where('EVENT_MANIPULATION', $event)->where('ACTION_TIMING', 'BEFORE')->first();
                if ($trigger === null || ! str_starts_with($body, 'FOR EACH ROW ')
                    || $normalize($trigger->ACTION_STATEMENT) !== $normalize(substr($body, strlen('FOR EACH ROW ')))) return false;
            }
        }
        return true;
    }

    /** Verify the migration-defined journal guards, including the SQLite collision predicate. */
    public function journalActive(): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return false;
        $sqlite = $driver === 'sqlite';
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Held release journal is immutable'); END"
            : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held release journal is immutable'";
        $table = 'held_cash_release_journal';
        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'release_journal_reject_'.strtolower($event);
            if ($sqlite) {
                $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)
                    ->where('tbl_name', $table)->first();
                $expected = "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}";
                if ($trigger === null || $normalize($trigger->sql) !== $normalize($expected)) return false;
            } else {
                $trigger = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                    ->where('TRIGGER_NAME', $name)->where('EVENT_OBJECT_TABLE', $table)
                    ->where('EVENT_MANIPULATION', $event)->where('ACTION_TIMING', 'BEFORE')->first();
                if ($trigger === null || $normalize($trigger->ACTION_STATEMENT) !== $normalize($body)) return false;
            }
        }
        if ($sqlite) {
            $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'release_journal_reject_replace')
                ->where('tbl_name', $table)->first();
            $expected = "CREATE TRIGGER release_journal_reject_replace BEFORE INSERT ON held_cash_release_journal
                FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.id
                OR j.operation_uuid = NEW.operation_uuid OR j.proposal_uuid = NEW.proposal_uuid) {$body}";
            if ($trigger === null || $normalize($trigger->sql) !== $normalize($expected)) return false;
        }
        return true;
    }

    /** Verify the actual migration-defined reinstatement guards, not merely their names. */
    public function reinstatementActive(): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return false;
        $sqlite = $driver === 'sqlite';
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Shadow reinstatement is immutable'); END"
            : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shadow reinstatement is immutable'";
        $table = 'held_cash_shadow_reinstatements';
        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'shadow_restore_reject_'.strtolower($event);
            if ($sqlite) {
                $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)
                    ->where('tbl_name', $table)->first();
                $expected = "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} {$body}";
                if ($trigger === null || $normalize($trigger->sql) !== $normalize($expected)) return false;
            } else {
                $trigger = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                    ->where('TRIGGER_NAME', $name)->where('EVENT_OBJECT_TABLE', $table)
                    ->where('EVENT_MANIPULATION', $event)->where('ACTION_TIMING', 'BEFORE')->first();
                if ($trigger === null || $normalize($trigger->ACTION_STATEMENT) !== $normalize($body)) return false;
            }
        }
        if ($sqlite) {
            $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'shadow_restore_reject_replace')
                ->where('tbl_name', $table)->first();
            $expected = "CREATE TRIGGER shadow_restore_reject_replace BEFORE INSERT ON held_cash_shadow_reinstatements
                FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements s WHERE s.id = NEW.id
                OR (s.journal_id = NEW.journal_id AND s.revision_id = NEW.revision_id)) {$body}";
            if ($trigger === null || $normalize($trigger->sql) !== $normalize($expected)) return false;
        }
        return true;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) DB::unprepared("CREATE TRIGGER {$name} {$definition}");
        }
        if (! $this->active()) throw new \LogicException('Release proposal protection missing.');
    }
}
