<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Correction documents are append-only, even when bypassing Eloquent. */
final class FinancialCorrectionDocumentProtection
{
    private const DOCUMENTS = [
        'payment_refunds' => ['refund_doc', 'disbursement_reference'],
        'payment_account_transfers' => ['transfer_doc', 'payment_id'],
    ];

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported correction document protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $body = $sqlite
            ? "BEGIN SELECT RAISE(ABORT, 'Financial correction document is immutable'); END"
            : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial correction document is immutable'";
        $definitions = [];
        foreach (self::DOCUMENTS as $table => [$prefix, $key]) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $prefix.'_reject_'.strtolower($event);
                $definitions[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
            }
            // SQLite REPLACE may skip DELETE triggers. Match both unique keys.
            if ($sqlite) {
                $definitions[$prefix.'_reject_replace'] = [$table, 'INSERT',
                    "BEFORE INSERT ON {$table} FOR EACH ROW WHEN EXISTS (SELECT 1 FROM {$table} d WHERE d.id = NEW.id OR d.{$key} = NEW.{$key}) {$body}", $body];
            }
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
                throw new \LogicException('Unexpected correction document trigger: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()
                ->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected correction document trigger: '.$name);
            }
        }
        return true;
    }

    public function active(): bool
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) return false;
        }
        // InnoDB cascades bypass child triggers, so retain restrictive parent links.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::DOCUMENTS as $table => $_) {
                $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                    ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)->get();
                if ($rows->count() !== ($table === 'payment_refunds' ? 3 : 4)
                    || $rows->contains(fn ($row) => ! in_array($row->DELETE_RULE, ['RESTRICT', 'NO ACTION'], true)
                        || ! in_array($row->UPDATE_RULE, ['RESTRICT', 'NO ACTION'], true))) return false;
            }
        }
        return true;
    }

    public function install(): void
    {
        foreach ($this->definitions() as $name => $definition) {
            if (! $this->verified($name, $definition)) {
                DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
            }
        }
        if (! $this->active()) throw new \LogicException('Financial correction document protection incomplete.');
    }
}
