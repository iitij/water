<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Check concrete source identity, value and effective day at registry INSERT.
 * Canonical hashes, held-liability groups and exclusive writer authorization
 * remain independently verified by the PHP evidence adapters.
 */
final class RegistrySourceIdentityProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported registry source identity engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $local = static fn (string $stamp): string => $sqlite
            ? "date({$stamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$stamp}) + 10800) SECOND))";
        // Ledger money is a bounded two-place decimal, not an unbounded
        // treasury bigint. SQLite compares the same representable major value;
        // MySQL uses exact DECIMAL multiplication.
        $amount = $sqlite ? 't.amount = NEW.amount_minor / 100.0' : 't.amount * 100 = NEW.amount_minor';
        $identity = static fn (string $id): string => "HEX(CAST({$id} AS CHAR)) = HEX(NEW.source_id)";
        $cases = [];
        foreach ([
            ['invoice', \App\Models\Invoice::class, 'invoices', 'DATE(d.bill_date)'],
            ['invoice_reversal', \App\Models\Invoice::class, 'invoices', $local('d.voided_at')],
            ['payment', \App\Models\Payment::class, 'payments', 'DATE(d.payment_date)'],
            ['payment_reversal', \App\Models\Payment::class, 'payments', "COALESCE(".$local('d.voided_at').",
                (SELECT ".$local('x.created_at')." FROM payment_account_transfers x WHERE x.payment_id = d.id{$lock}))"],
            ['payment_transfer_in', \App\Models\PaymentAccountTransfer::class, 'payment_account_transfers', $local('d.created_at')],
            ['payment_refund', \App\Models\PaymentRefund::class, 'payment_refunds', 'DATE(d.disbursed_on)'],
            ['held_cash_credit', \App\Models\TreasuryHeldCashCredit::class, 'treasury_held_cash_credits', 'DATE(d.effective_on)'],
        ] as [$type, $class, $table, $date]) {
            $cases[] = "(HEX(t.type) = '".strtoupper(bin2hex($type))."' AND HEX(t.reference_type) = '".strtoupper(bin2hex($class))."'
                AND EXISTS (SELECT 1 FROM {$table} d WHERE d.id = t.reference_id AND {$date} = o.effective_on{$lock}))";
        }
        $op = "o.operation_uuid = NEW.operation_uuid AND o.scope_key = NEW.scope_key";
        $ledger = "NEW.leg = 'subscriber' AND EXISTS (SELECT 1 FROM transactions t
            JOIN financial_posting_operations o ON {$op}
            WHERE ".$identity('t.id')." AND t.deleted_at IS NULL AND {$amount}
            AND (".implode(' OR ', $cases)."){$lock})";
        $posting = "NEW.leg = 'treasury' AND EXISTS (SELECT 1 FROM treasury_postings t
            JOIN financial_posting_operations o ON {$op}
            WHERE ".$identity('t.id')." AND t.amount_minor = NEW.amount_minor AND t.effective_on = o.effective_on{$lock})";
        $opening = "NEW.leg = 'opening' AND EXISTS (SELECT 1 FROM treasury_accounts t
            JOIN financial_posting_operations o ON {$op}
            WHERE ".$identity('t.id')." AND t.opening_minor = NEW.amount_minor AND t.opening_on = o.effective_on{$lock})";
        $condition = preg_replace('/\s+/', ' ', trim("
            (NEW.source_type = 'ledger_transaction' AND NOT COALESCE(({$ledger}), 0))
            OR (NEW.source_type = 'treasury_posting' AND NOT COALESCE(({$posting}), 0))
            OR (NEW.source_type = 'treasury_opening' AND NOT COALESCE(({$opening}), 0))"));
        $message = 'Financial registry source identity is invalid';
        $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = NEW.scope_key FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        return ['fin_registry_source_identity_insert' => ['financial_posting_events', "BEFORE INSERT ON financial_posting_events FOR EACH ROW {$body}", $body, 'INSERT']];
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body, $event] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            return $row !== null && $row->tbl_name === $table && $row->sql === "CREATE TRIGGER {$name} {$sql}";
        }
        $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
        return $row !== null && $row->EVENT_OBJECT_TABLE === $table && $row->EVENT_MANIPULATION === $event
            && $row->ACTION_TIMING === 'BEFORE' && preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) === $body;
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
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Registry source identity protection incomplete.');
    }
}
