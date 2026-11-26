<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Append-only source documents. Cancellation and discharge are distinct inserts. */
final class HeldCashDocumentProtection
{
    private const DOCUMENTS = [
        'treasury_held_cash_liabilities' => ['held_lot', ['id', 'treasury_cash_adjustment_id', 'treasury_posting_id', 'operation_uuid']],
        'treasury_held_cash_assignments' => ['held_assignment', ['operation_uuid', 'reference']],
        'treasury_held_cash_payout_authorizations' => ['held_auth', ['operation_uuid', 'reference']],
        'treasury_held_cash_payout_cancellations' => ['held_cancel', ['operation_uuid']],
        'treasury_held_cash_payouts' => ['held_payout', ['operation_uuid', 'reference']],
        'treasury_held_cash_credits' => ['held_credit', ['id', 'operation_uuid', 'reference']],
    ];

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported held cash protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $body = $sqlite
            ? "BEGIN SELECT RAISE(ABORT, 'Held cash document is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held cash document is immutable'";
        $definitions = [];
        foreach (self::DOCUMENTS as $table => [$prefix, $keys]) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $definitions[$prefix.'_reject_'.strtolower($event)] = "BEFORE {$event} ON {$table} {$body}";
            }
            if ($sqlite) {
                // REPLACE otherwise implicitly deletes a conflicting row without
                // firing DELETE triggers when recursive_triggers is disabled.
                $conflict = implode(' OR ', array_map(fn ($key) => "d.{$key} = NEW.{$key}", $keys));
                $definitions[$prefix.'_reject_replace'] = "BEFORE INSERT ON {$table} FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM {$table} d WHERE {$conflict}) {$body}";
            }
        }
        return $definitions;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) DB::unprepared("CREATE TRIGGER {$name} {$definition}");
        }
        if (! $this->active()) throw new \LogicException('Held cash protection missing after installation.');
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->exists($name)) return false;
        }
        return true;
    }

    private function exists(string $name): bool
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()
            : FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->exists();
    }
}
