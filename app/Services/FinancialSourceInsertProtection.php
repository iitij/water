<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Prospective period fence for dated financial sources. Protect original
 * dates, core document identity/amounts and deletion. Live collection
 * projections and later reversal workflows need independent controls.
 * Not authorization for arbitrary open-period DML or a DDL/cascade fence.
 */
final class FinancialSourceInsertProtection
{
    private const DATES = [
        'invoices' => 'bill_date',
        'payments' => 'payment_date',
        'payment_refunds' => 'disbursed_on',
        'treasury_accounts' => 'opening_on',
        'treasury_postings' => 'effective_on',
    ];

    // Live amount_paid/status on invoices and current treasury balances are
    // deliberately excluded: later-period collections may update projections.
    private const PROTECTED_FIELDS = [
        'invoices' => ['subscriber_id', 'meter_id', 'invoice_type', 'bill_number',
            'billing_period_start', 'billing_period_end', 'consumption_fee',
            'previous_balance', 'credit_applied', 'total_amount', 'deleted_at', 'created_at'],
        'payments' => ['subscriber_id', 'meter_id', 'invoice_id', 'user_id', 'receipt_number',
            'amount', 'payment_method', 'reference_number', 'reason', 'sync_uuid',
            'cash_session_id', 'deleted_at', 'created_at'],
        'treasury_accounts' => ['code', 'opening_reference'],
    ];

    private function definitions(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported financial source fence driver.');
        }
        $sqlite = $driver === 'sqlite';
        $current = $sqlite ? '' : ' FOR UPDATE';
        $defs = [];
        foreach (self::DATES as $table => $column) {
            $date = "NEW.{$column}";
            $invalid = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                AND s.active_freeze_uuid IS NOT NULL{$current})
                OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on
                    AND (p.state <> 'OPEN'
                        OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                        OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
            $invalid = preg_replace('/\s+/', ' ', trim($invalid));
            $message = 'Financial source period is fenced';
            $body = $sqlite ? "WHEN {$invalid} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
            $name = 'fin_source_'.$table.'_insert';
            $defs[$name] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body, 'INSERT'];

            $oldInvalid = str_replace("NEW.{$column}", "OLD.{$column}", $invalid);
            $mutationMessage = 'Financial source mutation is fenced';
            $changes = [];
            foreach (array_merge(['id', $column], self::PROTECTED_FIELDS[$table] ?? []) as $field) {
                $changes[] = $sqlite ? "OLD.{$field} IS NOT NEW.{$field}"
                    : "NOT (OLD.{$field} <=> NEW.{$field})";
            }
            // Refunds and treasury posting documents are append-only in a
            // fenced period, including updates to non-date evidence.
            $changed = isset(self::PROTECTED_FIELDS[$table]) ? '('.implode(' OR ', $changes).')' : '1 = 1';
            foreach (['UPDATE' => "({$changed}) AND (({$oldInvalid}) OR ({$invalid}))",
                'DELETE' => $oldInvalid] as $event => $condition) {
                $mutationBody = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$mutationMessage}'); END"
                    : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mutationMessage}'; END IF; END";
                $mutationName = 'fin_source_'.$table.'_'.strtolower($event);
                $defs[$mutationName] = [$table, "BEFORE {$event} ON {$table} FOR EACH ROW {$mutationBody}", $mutationBody, $event];
            }
        }
        return $defs;
    }

    private function verified(string $name, array $definition): bool
    {
        [$table, $sql, $body, $event] = $definition;
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($row === null) return false;
            if ($row->tbl_name !== $table || $row->sql !== "CREATE TRIGGER {$name} {$sql}") {
                throw new \LogicException('Unexpected source period fence: '.$name);
            }
        } else {
            $row = FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $name)->first();
            if ($row === null) return false;
            if ($row->EVENT_OBJECT_TABLE !== $table || $row->EVENT_MANIPULATION !== $event
                || $row->ACTION_TIMING !== 'BEFORE'
                || preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)) !== $body) {
                throw new \LogicException('Unexpected source period fence: '.$name);
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
            if (! $this->verified($name, $definition)) DB::unprepared("CREATE TRIGGER {$name} {$definition[1]}");
        }
        if (! $this->active()) throw new \LogicException('Financial source period protection incomplete.');
    }
}
