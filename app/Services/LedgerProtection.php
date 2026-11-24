<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LedgerProtection
{
    private function names(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? ['ledger_reject_update', 'ledger_reject_delete', 'ledger_guard_subscriber_delete', 'ledger_guard_meter_delete', 'ledger_reject_replace']
            : ['ledger_reject_update', 'ledger_reject_delete'];
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
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)
            || ! Schema::hasIndex('transactions', 'transactions_reference_posting_unique', 'unique')) {
            return false;
        }
        foreach ($this->names() as $name) {
            if (! $this->exists($name)) return false;
        }
        if (DB::getDriverName() !== 'sqlite') {
            // InnoDB cascades do not invoke child triggers.
            foreach (['subscriber_id', 'meter_id'] as $column) {
                $constraints = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                    ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', 'transactions')
                    ->whereIn('CONSTRAINT_NAME', ['transactions_'.$column.'_foreign', 'transactions_'.$column.'_ledger_restrict'])
                    ->get();
                if ($constraints->count() !== 1 || ! in_array($constraints->first()->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function install(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Unsupported ledger protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $equal = static fn (string $column): string => $sqlite
            ? "OLD.$column IS NEW.$column"
            : "OLD.$column <=> NEW.$column";
        // Compare text byte-for-byte even on case-insensitive MySQL collations.
        $textEqual = static fn (string $column): string => $sqlite
            ? "OLD.$column IS NEW.$column"
            : "BINARY OLD.$column <=> BINARY NEW.$column";
        $fixed = implode(' AND ', array_merge(
            array_map($equal, ['id', 'subscriber_id', 'amount', 'reference_id', 'created_at', 'updated_at', 'deleted_at']),
            array_map($textEqual, ['type', 'description', 'reference_type'])
        ));
        $sameProvenance = implode(' AND ', [$equal('meter_id'), $equal('retired_meter_id'), $textEqual('retired_meter_code')]);
        $meterCode = $sqlite ? 'm.unique_code IS NEW.retired_meter_code' : 'BINARY m.unique_code <=> BINARY NEW.retired_meter_code';
        $retirement = "OLD.meter_id IS NOT NULL AND OLD.retired_meter_id IS NULL AND OLD.retired_meter_code IS NULL
            AND NEW.meter_id IS NULL AND NEW.retired_meter_id = OLD.meter_id
            AND NEW.retired_meter_code IS NOT NULL
            AND EXISTS (SELECT 1 FROM meters m WHERE m.id = OLD.meter_id AND m.subscriber_id = OLD.subscriber_id AND ($meterCode))";
        // COALESCE prevents SQL NULL from bypassing the predicate.
        $invalid = "NOT COALESCE(($fixed) AND (($sameProvenance) OR ($retirement)), 0)";
        if ($sqlite) {
            $definitions = [
                'ledger_reject_update' => "BEFORE UPDATE ON transactions FOR EACH ROW WHEN $invalid
                    BEGIN SELECT RAISE(ABORT, 'Ledger posting is immutable'); END",
                'ledger_reject_delete' => "BEFORE DELETE ON transactions FOR EACH ROW
                    BEGIN SELECT RAISE(ABORT, 'Ledger posting is immutable'); END",
                // SQLite REPLACE can implicitly delete without firing DELETE
                // triggers when recursive_triggers is disabled. Stop it at INSERT.
                'ledger_reject_replace' => "BEFORE INSERT ON transactions FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM transactions t WHERE t.id = NEW.id OR (
                        NEW.reference_type IS NOT NULL AND NEW.reference_id IS NOT NULL
                        AND t.reference_type = NEW.reference_type AND t.reference_id = NEW.reference_id AND t.type = NEW.type
                    ))
                    BEGIN SELECT RAISE(ABORT, 'Ledger posting already exists'); END",
                'ledger_guard_subscriber_delete' => "BEFORE DELETE ON subscribers FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM transactions WHERE subscriber_id = OLD.id)
                    BEGIN SELECT RAISE(ABORT, 'Subscriber has ledger postings'); END",
                'ledger_guard_meter_delete' => "BEFORE DELETE ON meters FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM transactions WHERE meter_id = OLD.id)
                    BEGIN SELECT RAISE(ABORT, 'Retire meter provenance before deletion'); END",
            ];
        } else {
            $definitions = [
                'ledger_reject_update' => "BEFORE UPDATE ON transactions FOR EACH ROW BEGIN
                    IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger posting is immutable'; END IF; END",
                'ledger_reject_delete' => "BEFORE DELETE ON transactions FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger posting is immutable'",
            ];
        }
        foreach ($definitions as $name => $definition) {
            if (! $this->exists($name)) {
                DB::unprepared("CREATE TRIGGER $name $definition");
            }
        }
    }
}
