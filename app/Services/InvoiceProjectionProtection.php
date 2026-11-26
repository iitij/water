<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/** Registered invoice projections must be supported by persisted financial evidence. */
final class InvoiceProjectionProtection
{
    private const NAME = 'reg_invoice_projection_update';

    private function definition(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported invoice projection protection driver.');
        }
        $sqlite = $driver === 'sqlite';
        $same = static fn (string $col): string => $sqlite
            ? "OLD.{$col} IS NEW.{$col}"
            : (in_array($col, ['status', 'void_reason'], true)
                ? "BINARY OLD.{$col} <=> BINARY NEW.{$col}" : "OLD.{$col} <=> NEW.{$col}");
        $hex = strtoupper(bin2hex(Invoice::class));
        $registered = "EXISTS (SELECT 1 FROM transactions t JOIN financial_posting_events e
            ON e.source_type = 'ledger_transaction' AND e.scope_key = 'water' AND e.leg = 'subscriber'
            AND e.source_id = CAST(t.id AS CHAR)
            WHERE HEX(t.reference_type) = '{$hex}' AND t.reference_id = OLD.id
            AND t.type IN ('invoice', 'invoice_reversal'))";
        $revision = "(SELECT r.id FROM payment_allocation_revisions r
            WHERE r.subscriber_id = OLD.subscriber_id ORDER BY r.revision DESC LIMIT 1)";
        $paid = "COALESCE((SELECT SUM(a.amount_minor) FROM payment_allocations a
            WHERE a.revision_id = {$revision} AND a.invoice_id = OLD.id), 0) / 100.0";
        $reversed = "EXISTS (SELECT 1 FROM transactions t WHERE HEX(t.reference_type) = '{$hex}'
            AND t.reference_id = OLD.id AND t.type = 'invoice_reversal' AND t.deleted_at IS NULL
            AND t.subscriber_id = OLD.subscriber_id AND t.amount = OLD.total_amount)";
        $voidSame = implode(' AND ', array_map($same, ['voided_at', 'void_reason', 'voided_by']));
        $allSame = implode(' AND ', array_merge([$same('status'), $same('amount_paid')], [$voidSame]));
        $statusConsistent = "((NEW.status = 'paid' AND NEW.amount_paid = NEW.total_amount)
            OR (NEW.status = 'issued' AND NEW.amount_paid = 0 AND NEW.amount_paid < NEW.total_amount)
            OR (NEW.status = 'partially_paid' AND NEW.amount_paid > 0 AND NEW.amount_paid < NEW.total_amount)
            OR (NEW.status = 'overdue' AND NEW.amount_paid < NEW.total_amount))";
        // Allocation and derived status are written in separate statements by the allocator.
        // Only a change to a projection requires fresh evidence; unrelated metadata is not blocked.
        $active = "OLD.status NOT IN ('draft', 'cancelled') AND NEW.status NOT IN ('draft', 'cancelled')
            AND ({$voidSame}) AND NEW.amount_paid >= 0 AND NEW.amount_paid <= NEW.total_amount
            AND ({$same('amount_paid')} OR ({$revision} IS NOT NULL AND NEW.amount_paid = ({$paid})))
            AND ({$same('status')} OR {$statusConsistent})";
        $cancel = "OLD.status NOT IN ('draft', 'cancelled') AND NEW.status = 'cancelled'
            AND NEW.amount_paid = 0 AND NEW.voided_at IS NOT NULL
            AND NEW.void_reason IS NOT NULL AND LENGTH(TRIM(NEW.void_reason)) >= 10
            AND ({$reversed})";
        $condition = preg_replace('/\s+/', ' ', trim("({$registered}) AND NOT COALESCE(({$allSame}) OR ({$active}) OR ({$cancel}), 0)"));
        $message = 'Registered invoice projection requires financial evidence';
        $body = $sqlite
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        return ["BEFORE UPDATE ON invoices FOR EACH ROW {$body}", $body];
    }

    public function active(): bool
    {
        [$sql, $body] = $this->definition();
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', self::NAME)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== 'invoices' || $row->sql !== 'CREATE TRIGGER '.self::NAME.' '.$sql) {
                throw new \LogicException('Unexpected invoice projection trigger definition.');
            }
        } else {
            $row = DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('TRIGGER_NAME', self::NAME)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== 'invoices' || $row->EVENT_MANIPULATION !== 'UPDATE'
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected invoice projection trigger definition.');
            }
        }
        return true;
    }

    public function install(): void
    {
        if (! $this->active()) {
            DB::unprepared('CREATE TRIGGER '.self::NAME.' '.$this->definition()[0]);
        }
        if (! $this->active()) throw new \LogicException('Invoice projection protection incomplete.');
    }
}
