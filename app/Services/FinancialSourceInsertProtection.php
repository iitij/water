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
        'cash_handovers' => 'received_on',
        'cash_session_fundings' => 'issued_on',
        'treasury_transfers' => 'dispatched_on',
        'treasury_transfer_receipts' => 'received_on',
        'treasury_bank_fees' => 'charged_on',
        'treasury_cash_adjustments' => 'adjusted_on',
        'treasury_bank_collections' => 'cleared_on',
        'treasury_late_cash_receipts' => 'received_on',
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

    private const UNIQUE_KEYS = [
        'invoices' => ['bill_number'],
        'payments' => ['receipt_number', 'sync_uuid'],
        'payment_refunds' => ['disbursement_reference'],
        'treasury_accounts' => ['code', 'opening_reference'],
        'cash_handovers' => ['reference'],
        'cash_session_fundings' => ['cash_session_id', 'reference'],
        'treasury_transfers' => ['reference'],
        'treasury_transfer_receipts' => ['treasury_transfer_id', 'reference'],
        'treasury_bank_fees' => ['bank_statement_line_id', 'reference'],
        'treasury_cash_adjustments' => ['treasury_cash_count_id', 'treasury_cash_count_review_id', 'reference'],
        'treasury_bank_collections' => ['bank_statement_line_id', 'payment_id', 'reference'],
        'treasury_late_cash_receipts' => ['late_receipt_review_id', 'reference'],
        'treasury_postings' => ['cash_handover_id', 'payment_refund_id', 'evidence_reference',
            'cash_session_funding_id', 'treasury_transfer_id', 'treasury_transfer_receipt_id',
            'treasury_bank_fee_id', 'treasury_cash_adjustment_id', 'treasury_bank_collection_id',
            'treasury_late_cash_receipt_id', 'held_cash_payout_uuid'],
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
            if (in_array($table, ['invoices', 'payments'], true)) {
                // A reversal is a separate financial event. Its own effective
                // date, not the original document date, controls the fence.
                $oldVoid = str_replace("NEW.{$column}", 'OLD.voided_at', $invalid);
                $newVoid = str_replace("NEW.{$column}", 'NEW.voided_at', $invalid);
                $voidFields = ['voided_at', 'void_reason', 'voided_by'];
                if ($table === 'payments') $voidFields[] = 'voided_via';
                $voidChanges = [];
                foreach ($voidFields as $field) {
                    $voidChanges[] = $sqlite ? "OLD.{$field} IS NOT NEW.{$field}"
                        : "NOT (BINARY OLD.{$field} <=> BINARY NEW.{$field})";
                }
                if ($table === 'invoices') {
                    $voidChanges[] = "((OLD.status = 'cancelled') <> (NEW.status = 'cancelled'))";
                }
                $voidChanged = implode(' OR ', $voidChanges);
                $conditions = [
                    'INSERT' => "NEW.voided_at IS NOT NULL AND ({$newVoid})",
                    'UPDATE' => "({$voidChanged}) AND (({$oldVoid}) OR ({$newVoid})
                        OR (OLD.voided_at IS NULL AND NEW.voided_at IS NULL AND (({$oldInvalid}) OR ({$invalid}))))",
                    'DELETE' => "OLD.voided_at IS NOT NULL AND ({$oldVoid})",
                ];
                foreach ($conditions as $event => $condition) {
                    $condition = preg_replace('/\s+/', ' ', trim($condition));
                    $msg = 'Financial source reversal date is fenced';
                    $extra = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                        : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
                    $defs['fin_source_'.$table.'_void_'.strtolower($event)] = [$table,
                        "BEFORE {$event} ON {$table} FOR EACH ROW {$extra}", $extra, $event];
                }
            }
            if ($table === 'treasury_accounts') {
                $fixed = [];
                foreach (['opening_minor', 'opened_by', 'created_at'] as $field) {
                    $fixed[] = $sqlite ? "OLD.{$field} IS NOT NEW.{$field}" : "NOT (OLD.{$field} <=> NEW.{$field})";
                }
                foreach (['code', 'channel', 'opening_reference', 'opening_evidence'] as $field) {
                    $fixed[] = $sqlite ? "OLD.{$field} IS NOT NEW.{$field}" : "NOT (BINARY OLD.{$field} <=> BINARY NEW.{$field})";
                }
                $condition = '('.implode(' OR ', $fixed).") AND (({$oldInvalid}) OR ({$invalid}))";
                $msg = 'Treasury opening evidence is fenced';
                $extra = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                    : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
                $defs['fin_source_treasury_opening_update'] = [$table, "BEFORE UPDATE ON {$table} FOR EACH ROW {$extra}", $extra, 'UPDATE'];
            }
            if ($sqlite) {
                // INSERT OR REPLACE and UPDATE OR REPLACE can delete a
                // conflicting row without invoking its DELETE trigger.
                $keys = array_merge(['id'], self::UNIQUE_KEYS[$table]);
                $conflicts = implode(' OR ', array_map(fn ($key) => "d.{$key} = NEW.{$key}", $keys));
                $prior = str_replace("NEW.{$column}", "d.{$column}", $invalid);
                foreach (['INSERT', 'UPDATE'] as $event) {
                    $exclude = $event === 'UPDATE' ? ' AND d.id <> OLD.id' : '';
                    $condition = "EXISTS (SELECT 1 FROM {$table} d WHERE ({$conflicts}){$exclude} AND ({$prior}))";
                    $extra = "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Financial source replacement is fenced'); END";
                    $defs['fin_source_'.$table.'_replace_'.strtolower($event)] = [$table,
                        "BEFORE {$event} ON {$table} FOR EACH ROW {$extra}", $extra, $event];
                }
            }
        }
        // These sources already have independent append-only and replacement
        // guards. Add only the missing period-aware INSERT barrier. A later
        // withdrawal or discharge uses its own date, never its parent's date.
        $heldDates = [
            'treasury_held_cash_assignments' => 'NEW.effective_on',
            'treasury_held_cash_payout_authorizations' => 'NEW.effective_on',
            'treasury_held_cash_payout_cancellations' => 'NEW.effective_on',
            'treasury_held_cash_payouts' => 'NEW.effective_on',
            'treasury_held_cash_credits' => 'NEW.effective_on',
            'held_cash_release_proposals' => 'NEW.effective_on',
            'held_cash_release_withdrawals' => 'NEW.effective_on',
            'held_cash_release_journal' => 'NEW.effective_on',
            'treasury_held_cash_liabilities' => "(SELECT o.effective_on FROM financial_posting_operations o WHERE o.operation_uuid = NEW.operation_uuid{$current})",
            'held_cash_active_releases' => "(SELECT j.effective_on FROM held_cash_release_journal j WHERE j.id = NEW.journal_id{$current})",
        ];
        foreach ($heldDates as $table => $date) {
            $condition = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                AND s.active_freeze_uuid IS NOT NULL{$current})
                OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on
                    AND (p.state <> 'OPEN'
                        OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                        OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $msg = 'Held cash source period is fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
            $defs['fin_held_'.$table.'_insert'] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body, 'INSERT'];
        }
        // Allocation history is an operational snapshot, not an independent
        // accounting posting. Its immutable revision timestamp selects the
        // operational period. A reinstatement must use the new revision's
        // date, never the date of the original release journal.
        // MySQL TIMESTAMP is an instant; use epoch arithmetic to avoid
        // depending on the reader's session time zone or time-zone tables.
        $localDate = static fn (string $timestamp): string => $sqlite
            ? "date({$timestamp}, '+3 hours')"
            : "DATE(DATE_ADD('1970-01-01 00:00:00', INTERVAL (UNIX_TIMESTAMP({$timestamp}) + 10800) SECOND))";
        $revisionDate = "(SELECT ".$localDate('r.created_at')." FROM payment_allocation_revisions r WHERE r.id = NEW.revision_id{$current})";
        foreach ([
            'payment_allocation_revisions' => $localDate('NEW.created_at'),
            'payment_allocations' => $revisionDate,
            'held_cash_shadow_reinstatements' => $revisionDate,
        ] as $table => $date) {
            $condition = "{$date} IS NULL
                OR EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                    AND s.active_freeze_uuid IS NOT NULL{$current})
                OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                    AND {$date} >= p.start_on AND {$date} < p.next_on
                    AND (p.state <> 'OPEN'
                        OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                        OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $msg = 'Allocation source period is fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
            $defs['fin_alloc_'.$table.'_insert'] = [$table, "BEFORE INSERT ON {$table} FOR EACH ROW {$body}", $body, 'INSERT'];
        }
        // InnoDB CASCADE and SET NULL do not invoke child triggers.
        // Guard the parent before its referential actions can touch a dated
        // source. Keep unrelated parent deletion behavior unchanged.
        $parents = [
            'subscribers' => ['invoices' => ['subscriber_id'], 'payments' => ['subscriber_id']],
            'meters' => ['invoices' => ['meter_id'], 'payments' => ['meter_id']],
            'invoices' => ['payments' => ['invoice_id']],
            'cash_sessions' => ['payments' => ['cash_session_id']],
            'users' => ['invoices' => ['voided_by'], 'payments' => ['user_id', 'voided_by',
                'void_requested_by', 'void_decided_by', 'whatsapp_sent_by']],
        ];
        foreach ($parents as $parent => $children) {
            $checks = [];
            foreach ($children as $child => $keys) {
                $link = implode(' OR ', array_map(fn ($key) => "d.{$key} = OLD.id", $keys));
                $date = 'd.'.self::DATES[$child];
                $fenced = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                    AND s.active_freeze_uuid IS NOT NULL{$current})
                    OR EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                        AND {$date} >= p.start_on AND {$date} < p.next_on
                        AND (p.state <> 'OPEN'
                            OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                            OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
                $checks[] = "EXISTS (SELECT 1 FROM {$child} d WHERE ({$link}) AND ({$fenced}){$current})";
            }
            $condition = preg_replace('/\s+/', ' ', trim(implode(' OR ', $checks)));
            $msg = 'Financial source parent deletion is fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
            $defs['fin_source_parent_'.$parent.'_delete'] = [$parent, "BEFORE DELETE ON {$parent} FOR EACH ROW {$body}", $body, 'DELETE'];
        }
        // A square deletion cascades through subscribers and meters on
        // InnoDB without invoking the intermediate parent triggers.
        // SQLite replacement can also delete a parent without its DELETE guard.
        $parentKeys = [
            'squares' => ['id', 'code'],
            'subscribers' => ['id'],
            'meters' => ['id', 'unique_code', 'subscriber_id'],
            'cash_sessions' => ['id'],
            'invoices' => ['id', 'bill_number'],
        ];
        foreach ($parentKeys as $parent => $keys) {
            $children = $parent === 'squares'
                ? ['invoices' => ['subscriber_id'], 'payments' => ['subscriber_id']]
                : $parents[$parent];
            $checks = [];
            foreach ($children as $child => $columns) {
                $link = $parent === 'squares'
                    ? "EXISTS (SELECT 1 FROM subscribers s WHERE s.id = d.subscriber_id AND s.square_id = OLD.id{$current})"
                    : '('.implode(' OR ', array_map(fn ($key) => "d.{$key} = OLD.id", $columns)).')';
                $dates = ['d.'.self::DATES[$child], 'd.voided_at'];
                $dateChecks = [];
                foreach ($dates as $date) {
                    $dateChecks[] = "EXISTS (SELECT 1 FROM financial_periods p WHERE p.scope_key = 'water'
                        AND {$date} >= p.start_on AND {$date} < p.next_on
                        AND (p.state <> 'OPEN'
                            OR EXISTS (SELECT 1 FROM financial_period_closures c WHERE c.period_id = p.id{$current})
                            OR EXISTS (SELECT 1 FROM financial_close_commands j WHERE j.period_id = p.id AND j.action = 'approve'{$current})){$current})";
                }
                $fenced = "EXISTS (SELECT 1 FROM financial_scopes s WHERE s.scope_key = 'water'
                    AND s.active_freeze_uuid IS NOT NULL{$current}) OR ".implode(' OR ', $dateChecks);
                $checks[] = "EXISTS (SELECT 1 FROM {$child} d WHERE ({$link}) AND ({$fenced}){$current})";
            }
            $condition = preg_replace('/\s+/', ' ', trim(implode(' OR ', $checks)));
            // Also protect a parent when only its child's reversal is closed.
            $msg = 'Financial source parent graph is fenced';
            $body = $sqlite ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$msg}'); END"
                : "BEGIN DECLARE locked_scope VARCHAR(64) DEFAULT NULL; SELECT scope_key INTO locked_scope FROM financial_scopes WHERE scope_key = 'water' FOR UPDATE; IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";
            $defs['fin_graph_'.$parent.'_delete'] = [$parent, "BEFORE DELETE ON {$parent} FOR EACH ROW {$body}", $body, 'DELETE'];
            if ($sqlite) {
                $conflicts = implode(' OR ', array_map(fn ($key) => "victim.{$key} = NEW.{$key}", $keys));
                $prior = str_replace('OLD.id', 'victim.id', $condition);
                foreach (['INSERT', 'UPDATE'] as $event) {
                    $exclude = $event === 'UPDATE' ? ' AND victim.id <> OLD.id' : '';
                    $collision = "EXISTS (SELECT 1 FROM {$parent} victim WHERE ({$conflicts}){$exclude} AND ({$prior}))";
                    $extra = "WHEN {$collision} BEGIN SELECT RAISE(ABORT, 'Financial source parent replacement is fenced'); END";
                    $defs['fin_graph_'.$parent.'_replace_'.strtolower($event)] = [$parent,
                        "BEFORE {$event} ON {$parent} FOR EACH ROW {$extra}", $extra, $event];
                }
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
