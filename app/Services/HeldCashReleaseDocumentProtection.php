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
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) return false;
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
