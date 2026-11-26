<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            // REPLACE implicitly deletes conflicting rows without DELETE triggers
            // when recursive_triggers is OFF. Block the conflict before it happens.
            $this->sqliteGuard('protect_user_identity_insert', 'INSERT',
                "EXISTS (SELECT 1 FROM users WHERE id = NEW.id OR email = NEW.email)");
            $this->sqliteGuard('protect_user_identity_update', 'UPDATE',
                "NEW.id IS NOT OLD.id OR EXISTS (SELECT 1 FROM users WHERE email = NEW.email AND id <> OLD.id)
                OR (OLD.disabled_at IS NOT NULL AND (NEW.disabled_at IS NOT OLD.disabled_at OR NEW.password IS NOT OLD.password))");
        } elseif ($driver === 'mysql') {
            $body = "BEGIN IF NOT (NEW.id <=> OLD.id) OR (OLD.disabled_at IS NOT NULL AND
                (NOT (NEW.disabled_at <=> OLD.disabled_at) OR NOT (BINARY NEW.password <=> BINARY OLD.password)))
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User identity cannot be replaced or reactivated'; END IF; END";
            $name = 'protect_user_identity_update';
            $existing = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
            if (! $existing) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON users FOR EACH ROW {$body}");
            } elseif ($existing->EVENT_OBJECT_TABLE !== 'users' || $existing->ACTION_TIMING !== 'BEFORE'
                || $existing->EVENT_MANIPULATION !== 'UPDATE' || $this->normalize($existing->ACTION_STATEMENT) !== $this->normalize($body)) {
                throw new RuntimeException('Unexpected user update guard definition; DBA review required.');
            }
        } else {
            throw new RuntimeException('User identity protection requires SQLite or MySQL.');
        }
        if (! Schema::hasIndex('users', ['disabled_at'])) {
            Schema::table('users', fn (Blueprint $table) => $table->index('disabled_at'));
        }
    }

    private function normalize(string $sql): string
    {
        return strtolower(preg_replace('/\s+/', '', str_replace(chr(96), '', $sql)));
    }

    private function sqliteGuard(string $name, string $event, string $predicate): void
    {
        $sql = "CREATE TRIGGER {$name} BEFORE {$event} ON users FOR EACH ROW WHEN {$predicate}
            BEGIN SELECT RAISE(ABORT, 'User identity cannot be replaced or reactivated'); END";
        $existing = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql');
        if ($existing === null) {
            DB::unprepared($sql);
        } elseif ($this->normalize($existing) !== $this->normalize($sql)) {
            throw new RuntimeException('Unexpected SQLite user guard definition; manual review required.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('User identity protection cannot be removed by rollback.');
    }
};
