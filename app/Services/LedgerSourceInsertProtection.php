<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Prospective source identity protection, not authorization of raw DML.
 * Reversal writers append the reversal before updating the void projection.
 * The original posting and the recording period must therefore be checked
 * independently of that not-yet-persisted projection.
 */
final class LedgerSourceInsertProtection
{
    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported ledger source insert engine.');
        }
        $sqlite = $driver === 'sqlite';
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $equal = static fn (string $a, string $b): string => $sqlite ? "{$a} IS {$b}" : "{$a} <=> {$b}";
        $local = static fn (string $stamp): string => $sqlite
            ? "date({$stamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$stamp}) + 10800) SECOND))";
        $period = static fn (string $date): string =>
            "EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                AND {$date} >= p.start_on AND {$date} < p.next_on AND p.state = 'OPEN'
                AND NOT EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$lock})
                AND NOT EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$lock}){$lock})";
        $meter = "(".$equal('NEW.meter_id', 'd.meter_id').") AND (".$equal('NEW.retired_meter_id', 'd.retired_meter_id').")
            AND HEX(COALESCE(NEW.retired_meter_code, '')) = HEX(COALESCE(d.retired_meter_code, ''))";
        $noMeter = 'NEW.meter_id IS NULL AND NEW.retired_meter_id IS NULL AND NEW.retired_meter_code IS NULL';
        $valid = [];
        foreach ([
            ['invoice', \App\Models\Invoice::class, 'invoices', 'subscriber_id', '-d.total_amount',
                "d.deleted_at IS NULL AND d.status NOT IN ('draft', 'cancelled') AND d.total_amount >= 0 AND ({$meter})", 'd.bill_date'],
            ['payment', \App\Models\Payment::class, 'payments', 'subscriber_id', 'd.amount',
                "d.deleted_at IS NULL AND d.voided_at IS NULL AND d.amount > 0 AND ({$meter})", 'd.payment_date'],
            ['payment_transfer_in', \App\Models\PaymentAccountTransfer::class, 'payment_account_transfers', 'to_subscriber_id', 'd.amount',
                "d.amount > 0 AND ({$noMeter})", $local('d.created_at')],
            ['payment_refund', \App\Models\PaymentRefund::class, 'payment_refunds', 'subscriber_id', '-d.amount',
                "d.amount > 0 AND ({$noMeter})", 'd.disbursed_on'],
            ['held_cash_credit', \App\Models\TreasuryHeldCashCredit::class, 'treasury_held_cash_credits', 'subscriber_id', 'd.amount',
                "d.amount > 0 AND ({$noMeter})", 'd.effective_on'],
        ] as [$type, $class, $table, $owner, $amount, $extra, $date]) {
            $hex = strtoupper(bin2hex($class));
            $valid[] = "(HEX(NEW.type) = '".strtoupper(bin2hex($type))."' AND HEX(NEW.reference_type) = '{$hex}'
                AND EXISTS (SELECT 1 FROM {$table} d WHERE d.id = NEW.reference_id
                    AND d.{$owner} = NEW.subscriber_id AND NEW.amount = {$amount} AND ({$extra})
                    AND ".$period($date)."{$lock}))";
        }
        // Read the original ledger effect, not a fabricated void timestamp.
        // The real effective date is subsequently certified by the registry.
        foreach (['invoice' => \App\Models\Invoice::class, 'payment' => \App\Models\Payment::class] as $type => $class) {
            $hex = strtoupper(bin2hex($class));
            $kind = strtoupper(bin2hex($type));
            $reversal = strtoupper(bin2hex($type.'_reversal'));
            $table = $type === 'invoice' ? 'invoices' : 'payments';
            $amount = $type === 'invoice' ? 'd.total_amount' : '-d.amount';
            $valid[] = "(HEX(NEW.type) = '{$reversal}' AND HEX(NEW.reference_type) = '{$hex}'
                AND EXISTS (SELECT 1 FROM {$table} d WHERE d.id = NEW.reference_id AND d.deleted_at IS NULL
                    AND d.subscriber_id = NEW.subscriber_id AND NEW.amount = {$amount} AND ({$meter}){$lock})
                AND EXISTS (SELECT 1 FROM transactions t JOIN financial_posting_events e
                    ON e.source_id = CAST(t.id AS CHAR) AND e.source_type = 'ledger_transaction'
                    AND e.leg = 'subscriber' AND e.scope_key = 'water'
                    WHERE HEX(t.type) = '{$kind}' AND HEX(t.reference_type) = '{$hex}'
                    AND t.reference_id = NEW.reference_id AND t.subscriber_id = NEW.subscriber_id
                    AND t.deleted_at IS NULL AND t.amount = -NEW.amount)
                AND ".$period($local('NEW.created_at')).")";
        }
        $scope = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'{$lock})";
        $active = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
            AND s.mode = 'ACTIVE' AND s.active_freeze_uuid IS NULL AND s.currency = 'YER'
            AND s.timezone = 'Asia/Aden' AND s.calendar_version = 1{$lock})";
        $condition = preg_replace('/\s+/', ' ', trim("({$scope}) AND (NOT ({$active}) OR NOT COALESCE((NEW.deleted_at IS NULL AND (".implode(' OR ', $valid).")), 0))"));
        $message = 'Financial ledger scope is fenced';
        $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        return ['fin_ledger_source_insert' => ['transactions', "BEFORE INSERT ON transactions FOR EACH ROW {$body}", $body, 'INSERT']];
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
        if (! $this->active()) throw new \LogicException('Ledger source insert protection incomplete.');
    }
}
