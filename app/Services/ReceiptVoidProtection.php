<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** A registered receipt cannot be marked void without the matching reversal. */
final class ReceiptVoidProtection
{
    private const NAME = 'reg_receipt_void_update';

    private function definition(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported receipt void protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $same = static fn (string $col): string => $sqlite
            ? "OLD.{$col} IS NEW.{$col}"
            : (in_array($col, ['void_reason', 'voided_via'], true)
                ? "BINARY OLD.{$col} <=> BINARY NEW.{$col}" : "OLD.{$col} <=> NEW.{$col}");
        $hex = strtoupper(bin2hex(Payment::class));
        $registered = "(EXISTS (SELECT 1 FROM transactions t JOIN financial_posting_events e
            ON e.source_type = 'ledger_transaction' AND e.scope_key = 'water' AND e.leg = 'subscriber'
            AND e.source_id = CAST(t.id AS CHAR)
            WHERE HEX(t.reference_type) = '{$hex}' AND t.reference_id = OLD.id
            AND t.type IN ('payment', 'payment_reversal'))
            OR EXISTS (SELECT 1 FROM financial_posting_operations o WHERE o.scope_key = 'water'
                AND o.operation_uuid = OLD.sync_uuid))";
        $meterEqual = $sqlite ? 't.meter_id IS OLD.meter_id AND t.retired_meter_id IS OLD.retired_meter_id'
            : 't.meter_id <=> OLD.meter_id AND t.retired_meter_id <=> OLD.retired_meter_id';
        $reasonEqual = $sqlite ? 'NEW.void_reason IS OLD.void_request_reason'
            : 'BINARY NEW.void_reason <=> BINARY OLD.void_request_reason';
        $reversed = "EXISTS (SELECT 1 FROM transactions t WHERE HEX(t.reference_type) = '{$hex}'
            AND t.reference_id = OLD.id AND t.type = 'payment_reversal' AND t.deleted_at IS NULL
            AND t.subscriber_id = OLD.subscriber_id AND t.amount = -OLD.amount AND ({$meterEqual}))";
        $unchanged = implode(' AND ', array_map($same, ['voided_at', 'void_reason', 'voided_by', 'voided_via']));
        $valid = "OLD.voided_at IS NULL AND OLD.void_reason IS NULL AND OLD.voided_by IS NULL AND OLD.voided_via IS NULL
            AND NEW.voided_at IS NOT NULL AND NEW.voided_by IS NOT NULL
            AND NEW.void_reason IS NOT NULL AND LENGTH(TRIM(NEW.void_reason)) >= 10
            AND OLD.payment_method = 'cash' AND ({$reversed})
            AND NOT EXISTS (SELECT 1 FROM payment_account_transfers x WHERE x.payment_id = OLD.id)
            AND NOT EXISTS (SELECT 1 FROM payment_refunds x WHERE x.payment_id = OLD.id)
            AND NOT EXISTS (SELECT 1 FROM closed_cash_corrections x WHERE x.duplicate_of_id = OLD.id)
            AND NOT EXISTS (SELECT 1 FROM late_receipt_reviews x WHERE x.payment_id = OLD.id AND x.status = 'approved')
            AND NOT EXISTS (SELECT 1 FROM treasury_bank_collections x WHERE x.payment_id = OLD.id)
            AND ((OLD.void_requested_at IS NULL AND NEW.voided_via = 'gated')
                OR (OLD.void_requested_at IS NOT NULL AND OLD.void_requested_by IS NOT NULL
                    AND NEW.voided_via = 'approval' AND NEW.voided_by <> OLD.void_requested_by AND ({$reasonEqual})))";
        $condition = preg_replace('/\s+/', ' ', trim("{$registered} AND NOT COALESCE(({$unchanged}) OR ({$valid}), 0)"));
        $message = 'Registered receipt void requires reversal evidence';
        $body = $sqlite
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        return ["BEFORE UPDATE ON payments FOR EACH ROW {$body}", $body];
    }

    public function active(): bool
    {
        [$sql, $body] = $this->definition();
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', self::NAME)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== 'payments' || $row->sql !== 'CREATE TRIGGER '.self::NAME.' '.$sql) {
                throw new \LogicException('Unexpected receipt void trigger definition.');
            }
        } else {
            $row = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('TRIGGER_NAME', self::NAME)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== 'payments' || $row->EVENT_MANIPULATION !== 'UPDATE'
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected receipt void trigger definition.');
            }
        }
        return true;
    }

    public function install(): void
    {
        if (! $this->active()) {
            DB::unprepared('CREATE TRIGGER '.self::NAME.' '.$this->definition()[0]);
        }
        if (! $this->active()) throw new \LogicException('Receipt void protection incomplete.');
    }
}
