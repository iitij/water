<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** Preserve registered document identity while permitting allocation and void projections. */
final class RegisteredDocumentProtection
{
    private function registered(string $class, string $row): string
    {
        $type = $class === Invoice::class ? 'invoice' : 'payment';
        $hex = strtoupper(bin2hex($class));
        $ledger = "EXISTS (SELECT 1 FROM transactions t JOIN financial_posting_events e
            ON e.source_id = CAST(t.id AS CHAR) AND e.source_type = 'ledger_transaction'
            AND e.scope_key = 'water' AND e.leg = 'subscriber'
            WHERE HEX(t.reference_type) = '{$hex}' AND t.reference_id = {$row}.id
            AND t.type IN ('{$type}', '{$type}_reversal'))";
        if ($class === Payment::class) {
            return "({$ledger} OR EXISTS (SELECT 1 FROM financial_posting_operations o
                WHERE o.scope_key = 'water' AND o.operation_uuid = {$row}.sync_uuid))";
        }
        return $ledger;
    }

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported registered document protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $equal = static fn (string $column): string => $sqlite
            ? "OLD.{$column} IS NEW.{$column}"
            : (in_array($column, ['invoice_type', 'bill_number', 'receipt_number', 'payment_method', 'reference_number', 'reason', 'sync_uuid', 'retired_meter_code'], true)
                ? "BINARY OLD.{$column} <=> BINARY NEW.{$column}" : "OLD.{$column} <=> NEW.{$column}");
        $provenance = implode(' AND ', array_map($equal, ['meter_id', 'retired_meter_id', 'retired_meter_code']));
        $meterCode = $sqlite ? 'm.unique_code IS NEW.retired_meter_code' : 'BINARY m.unique_code <=> BINARY NEW.retired_meter_code';
        $retirement = "OLD.meter_id IS NOT NULL AND OLD.retired_meter_id IS NULL AND OLD.retired_meter_code IS NULL
            AND NEW.meter_id IS NULL AND NEW.retired_meter_id = OLD.meter_id
            AND NEW.retired_meter_code IS NOT NULL
            AND EXISTS (SELECT 1 FROM meters m WHERE m.id = OLD.meter_id AND m.subscriber_id = OLD.subscriber_id AND ({$meterCode}))";
        $definitions = [];
        $add = function (string $name, string $table, string $event, string $condition) use (&$definitions, $sqlite): void {
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registered financial document is immutable'";
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Registered financial document is immutable'); END"
                : "BEGIN IF {$condition} THEN {$signal}; END IF; END";
            $definitions[$name] = [$table, $event, "BEFORE {$event} ON {$table} FOR EACH ROW {$body}", $body];
        };
        foreach ([
            'invoices' => [Invoice::class, 'reg_invoice', ['id', 'subscriber_id', 'invoice_type', 'bill_number', 'bill_date',
                'billing_period_start', 'billing_period_end', 'consumption_fee', 'previous_balance', 'credit_applied', 'total_amount', 'created_at', 'deleted_at'],
                ['bill_number']],
            'payments' => [Payment::class, 'reg_receipt', ['id', 'subscriber_id', 'invoice_id', 'user_id', 'receipt_number',
                'amount', 'payment_date', 'payment_method', 'reference_number', 'reason', 'sync_uuid', 'cash_session_id', 'created_at', 'deleted_at'],
                ['receipt_number', 'sync_uuid']],
        ] as $table => [$class, $prefix, $fields, $keys]) {
            $registered = $this->registered($class, 'OLD');
            $fixed = implode(' AND ', array_map($equal, $fields));
            $add($prefix.'_fixed_update', $table, 'UPDATE', "({$registered}) AND NOT COALESCE(({$fixed}) AND (({$provenance}) OR ({$retirement})), 0)");
            $linked = $class === Invoice::class
                ? " OR EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = OLD.id AND {$this->registered(Payment::class, 'p')})" : '';
            $add($prefix.'_reject_delete', $table, 'DELETE', "({$registered}){$linked}");
            if ($sqlite) {
                $conflicts = implode(' OR ', array_merge(['d.id = NEW.id'], array_map(fn ($key) => "d.{$key} = NEW.{$key}", $keys)));
                $linkedRow = $class === Invoice::class
                    ? " OR EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = d.id AND {$this->registered(Payment::class, 'p')})" : '';
                $add($prefix.'_reject_replace', $table, 'INSERT',
                    "EXISTS (SELECT 1 FROM {$table} d WHERE ({$conflicts}) AND (({$this->registered($class, 'd')}){$linkedRow}))");
            }
        }
        // MySQL cascades do not invoke child triggers; protect direct parent deletes.
        foreach (['subscribers' => 'subscriber_id', 'meters' => 'meter_id', 'cash_sessions' => 'cash_session_id'] as $table => $key) {
            $condition = "EXISTS (SELECT 1 FROM payments p WHERE p.{$key} = OLD.id AND {$this->registered(Payment::class, 'p')})";
            if ($table !== 'cash_sessions') {
                $condition .= " OR EXISTS (SELECT 1 FROM invoices i WHERE i.{$key} = OLD.id AND {$this->registered(Invoice::class, 'i')})";
            }
            $add('reg_doc_guard_'.$table.'_delete', $table, 'DELETE', $condition);
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
                throw new \LogicException('Unexpected registered document trigger: '.$name);
            }
        } else {
            $row = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected registered document trigger: '.$name);
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
            if (! $this->verified($name, $definition)) {
                DB::unprepared("CREATE TRIGGER {$name} {$definition[2]}");
            }
        }
        if (! $this->active()) throw new \LogicException('Registered financial document protection incomplete.');
    }
}
