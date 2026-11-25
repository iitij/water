<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\PaymentRefund;
use App\Models\TreasuryHeldCashCredit;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only document-to-registry diagnostic for one explicit financial month.
 * No automatic adoption, backfill, certified opening, activation or close.
 * Must run under the scope-first transaction. Legacy writers are not yet fenced.
 */
final class FinancialSourceCoverageDiagnostic
{
    public function __construct(private FinancialLedgerRegistryVerifier $verifier,
        private TreasuryHeldCashLiabilityService $held)
    {
    }

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() === 0) throw new DomainException('source_coverage_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->first();
        if (! $scope || ! $period) throw new DomainException('source_coverage_period_missing');
        // Canonical registry and actual source proof are prerequisites. Corrupt
        // evidence is a hard error, not a partial or successful coverage result.
        $registry = $this->verifier->verifyPeriod('water', $periodId);
        $this->held->verifyCoverage();
        $start = $period->start_on;
        $end = $period->next_on;
        $issues = [];
        $expected = [];
        $documents = 0;
        $issue = static function (string $code, string $table, string $id) use (&$issues): void {
            $issues[$code.'|'.$table.'|'.$id] = ['code' => $code, 'source' => $table, 'id' => $id];
        };
        $expect = static function (string $type, string $id, string $leg, string $date, ?string $group = null) use (&$expected, $issue): void {
            $key = $type.'|'.$id.'|'.$leg;
            if (isset($expected[$key])) {
                if ($expected[$key]['date'] !== $date) $issue('source_date_conflict', $type, $id);
                if ($group !== null) $expected[$key]['groups'][$group] = true;
                return;
            }
            $expected[$key] = ['type' => $type, 'id' => $id, 'leg' => $leg, 'date' => $date,
                'groups' => $group === null ? [] : [$group => true]];
        };
        $ledger = static function (string $class, int $id, string $kind, string $date, ?string $group = null) use ($expect, $issue): void {
            $rows = DB::table('transactions')->where('reference_type', $class)->where('reference_id', $id)
                ->where('type', $kind)->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== 1 || $rows[0]->deleted_at !== null) {
                $issue('document_ledger_missing_duplicate_or_deleted', $class, $id.':'.$kind);
                return;
            }
            $expect('ledger_transaction', (string) $rows[0]->id, 'subscriber', $date, $group);
        };
        $posting = static function (string $column, int $id, string $kind, string $date, ?string $group = null) use ($expect, $issue): void {
            $rows = DB::table('treasury_postings')->where($column, $id)->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== 1 || $rows[0]->kind !== $kind) {
                $issue('document_treasury_posting_missing_or_duplicate', $column, (string) $id);
                return;
            }
            $row = $rows[0];
            if ($row->effective_on !== $date) $issue('treasury_document_date_mismatch', $column, (string) $id);
            $expect('treasury_posting', (string) $row->id, 'treasury', $date, $group);
        };
        $rows = static fn (string $table, string $column) => DB::table($table)
            ->where($column, '>=', $start)->where($column, '<', $end)->orderBy('id')->lockForUpdate()->get();
        $date = static fn ($value): string => substr((string) $value, 0, 10);

        foreach ($rows('invoices', 'bill_date') as $doc) {
            if ($doc->status === 'draft' && $doc->deleted_at === null) continue;
            $documents++;
            if ($doc->deleted_at !== null) $issue('deleted_financial_document', 'invoices', (string) $doc->id);
            $ledger(Invoice::class, (int) $doc->id, 'invoice', $date($doc->bill_date));
        }
        foreach ($rows('invoices', 'voided_at') as $doc) {
            $documents++;
            if ($doc->status !== 'cancelled' || $doc->deleted_at !== null) $issue('invoice_reversal_state', 'invoices', (string) $doc->id);
            $ledger(Invoice::class, (int) $doc->id, 'invoice_reversal', $date($doc->voided_at));
        }
        foreach ($rows('payments', 'payment_date') as $doc) {
            $documents++;
            if ($doc->deleted_at !== null) $issue('deleted_financial_document', 'payments', (string) $doc->id);
            $group = null;
            if (in_array($doc->payment_method, ['check', 'bank_transfer'], true)) {
                $group = 'collection:'.$doc->id;
                $collection = DB::table('treasury_bank_collections')->where('payment_id', $doc->id)->lockForUpdate()->get();
                if ($collection->count() !== 1) $issue('payment_collection_missing_or_duplicate', 'payments', (string) $doc->id);
                elseif ($collection[0]->cleared_on !== $date($doc->payment_date)) $issue('collection_dates_require_separate_proof', 'payments', (string) $doc->id);
            }
            $late = DB::table('late_receipt_reviews')->where('payment_id', $doc->id)->where('status', 'approved')->lockForUpdate()->get();
            if ($late->count() > 1) $issue('payment_late_decision_duplicate', 'payments', (string) $doc->id);
            foreach ($late as $review) {
                if ($review->settlement_method !== 'separate_handover') continue;
                $group = 'collection:'.$doc->id;
                $cash = DB::table('treasury_late_cash_receipts')->where('payment_id', $doc->id)->lockForUpdate()->get();
                if ($cash->count() !== 1) $issue('payment_late_cash_missing_or_duplicate', 'payments', (string) $doc->id);
                elseif ($cash[0]->received_on !== $date($doc->payment_date)) $issue('collection_dates_require_separate_proof', 'payments', (string) $doc->id);
            }
            $ledger(Payment::class, (int) $doc->id, 'payment', $date($doc->payment_date), $group);
        }
        foreach ($rows('payments', 'voided_at') as $doc) {
            $documents++;
            if (! in_array($doc->voided_via, ['gated', 'approval'], true)) $issue('void_date_or_policy_unverified', 'payments', (string) $doc->id);
            $ledger(Payment::class, (int) $doc->id, 'payment_reversal', $date($doc->voided_at));
        }
        foreach ($rows('payment_account_transfers', 'created_at') as $doc) {
            $documents++;
            // This specific immutable transfer document has no effective_on.
            // A same-day independent approval audit is required, not a generic
            // inference from the ledger's created_at timestamp.
            $proof = DB::table('audit_events')->where('event', 'payment.account_transferred')
                ->where('entity_type', PaymentAccountTransfer::class)->where('entity_id', (string) $doc->id)->lockForUpdate()->get();
            if ($proof->count() !== 1 || (int) $proof[0]->actor_user_id !== (int) $doc->approved_by
                || $date($proof[0]->occurred_at) !== $date($doc->created_at)) {
                $issue('transfer_effective_date_unverified', 'payment_account_transfers', (string) $doc->id);
            }
            $group = 'transfer:'.$doc->id;
            $ledger(Payment::class, (int) $doc->payment_id, 'payment_reversal', $date($doc->created_at), $group);
            $ledger(PaymentAccountTransfer::class, (int) $doc->id, 'payment_transfer_in', $date($doc->created_at), $group);
        }
        foreach ($rows('payment_refunds', 'disbursed_on') as $doc) {
            $documents++;
            $group = 'refund:'.$doc->id;
            // Eloquent's date cast persists midnight timestamps on SQLite,
            // while the MySQL DATE column returns YYYY-MM-DD. Use the same
            // authoritative document date as the refund/custody evidence.
            $refund = PaymentRefund::query()->lockForUpdate()->findOrFail($doc->id);
            $effectiveOn = $refund->disbursed_on->toDateString();
            $ledger(PaymentRefund::class, (int) $doc->id, 'payment_refund', $effectiveOn, $group);
            $posting('payment_refund_id', (int) $doc->id, 'payment_refund', $effectiveOn, $group);
        }
        foreach ($rows('treasury_held_cash_credits', 'effective_on') as $doc) {
            $documents++;
            $group = 'held_credit:'.$doc->id;
            $ledger(TreasuryHeldCashCredit::class, (int) $doc->id, 'held_cash_credit', $doc->effective_on, $group);
            $expect('held_cash_credit', (string) $doc->id, 'identified_liability', $doc->effective_on, $group);
        }
        foreach (['treasury_held_cash_assignments' => 'held_cash_assignment', 'treasury_held_cash_payouts' => 'held_cash_payout'] as $table => $type) {
            $docs = DB::table($table)->where('effective_on', '>=', $start)->where('effective_on', '<', $end)
                ->orderBy('operation_uuid')->lockForUpdate()->get();
            foreach ($docs as $doc) {
                $documents++;
                $group = $type.':'.$doc->operation_uuid;
                $expect($type, $doc->operation_uuid, 'identified_liability', $doc->effective_on, $group);
                $expect($type, $doc->operation_uuid, $type === 'held_cash_payout' ? 'treasury' : 'unidentified_liability', $doc->effective_on, $group);
                // The payout's registry treasury leg uses the payout UUID,
                // not a second treasury_posting registry identity.
                if ($type === 'held_cash_payout') {
                    $p = DB::table('treasury_postings')->where('held_cash_payout_uuid', $doc->operation_uuid)->lockForUpdate()->get();
                    if ($p->count() !== 1 || $p[0]->kind !== 'held_cash_payout' || $p[0]->effective_on !== $doc->effective_on) {
                        $issue('held_payout_posting_missing_or_conflicting', $table, $doc->operation_uuid);
                    }
                }
            }
        }
        foreach ($rows('treasury_accounts', 'opening_on') as $doc) {
            $documents++;
            $expect('treasury_opening', (string) $doc->id, 'opening', $doc->opening_on);
        }
        foreach ([
            ['cash_handovers', 'received_on', 'cash_handover_id', 'cash_handover'],
            ['cash_session_fundings', 'issued_on', 'cash_session_funding_id', 'collector_funding'],
            ['treasury_transfers', 'dispatched_on', 'treasury_transfer_id', 'internal_transfer_out'],
            ['treasury_transfer_receipts', 'received_on', 'treasury_transfer_receipt_id', 'internal_transfer_in'],
            ['treasury_bank_fees', 'charged_on', 'treasury_bank_fee_id', 'bank_fee'],
            ['treasury_cash_adjustments', 'adjusted_on', 'treasury_cash_adjustment_id', 'cash_count_adjustment'],
            ['treasury_bank_collections', 'cleared_on', 'treasury_bank_collection_id', 'bank_collection'],
            ['treasury_late_cash_receipts', 'received_on', 'treasury_late_cash_receipt_id', 'late_cash_receipt'],
        ] as [$table, $column, $fk, $kind]) {
            foreach ($rows($table, $column) as $doc) {
                $documents++;
                $group = in_array($kind, ['bank_collection', 'late_cash_receipt'], true) ? 'collection:'.$doc->payment_id : null;
                $posting($fk, (int) $doc->id, $kind, $doc->$column, $group);
            }
        }
        foreach ($rows('treasury_postings', 'effective_on') as $row) {
            if ($row->kind === 'held_cash_payout') {
                if (! isset($expected['held_cash_payout|'.$row->held_cash_payout_uuid.'|treasury'])) {
                    $issue('treasury_posting_without_period_document', 'treasury_postings', (string) $row->id);
                }
                continue;
            }
            if (! isset($expected['treasury_posting|'.$row->id.'|treasury'])) {
                $issue('treasury_posting_without_period_document', 'treasury_postings', (string) $row->id);
            }
        }
        // Creation time is only an anomaly discovery window, never the
        // accounting date of an unsupported/orphan ledger transaction.
        foreach ($rows('transactions', 'created_at') as $row) {
            if (! isset($expected['ledger_transaction|'.$row->id.'|subscriber'])) {
                $issue('ledger_row_without_period_document_or_date_proof', 'transactions', (string) $row->id);
            }
        }

        $groups = [];
        $matched = 0;
        foreach ($expected as $item) {
            $events = DB::table('financial_posting_events as e')->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
                ->where('e.scope_key', 'water')->where('e.source_type', $item['type'])->where('e.source_id', $item['id'])
                ->where('e.leg', $item['leg'])->select('e.*', 'o.period_id', 'o.effective_on')->lockForUpdate()->get();
            if ($events->count() !== 1) {
                $issue('required_registry_leg_missing_or_duplicate', $item['type'], $item['id'].':'.$item['leg']);
                continue;
            }
            $event = $events[0];
            if ((int) $event->period_id !== $periodId || $event->effective_on !== $item['date']) {
                $issue('registry_effective_date_mismatch', $item['type'], $item['id'].':'.$item['leg']);
                continue;
            }
            $matched++;
            foreach ($item['groups'] as $group => $_) $groups[$group][$event->operation_uuid] = true;
        }
        foreach ($groups as $group => $operations) {
            if (count($operations) !== 1) $issue('multi_leg_operation_split', 'operation_group', $group);
        }
        $events = DB::table('financial_posting_events as e')->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('o.period_id', $periodId)->select('e.*')->orderBy('e.id')->lockForUpdate()->get();
        foreach ($events as $event) {
            if (! isset($expected[$event->source_type.'|'.$event->source_id.'|'.$event->leg])) {
                $issue('registry_leg_without_period_document', $event->source_type, $event->source_id.':'.$event->leg);
            }
        }
        ksort($issues, SORT_STRING);
        return ['period_id' => $periodId, 'start_on' => $start, 'next_on' => $end,
            'documents_checked' => $documents, 'expected_legs' => count($expected), 'matched_legs' => $matched,
            'registry_events' => $registry['events'], 'issues' => array_values($issues),
            'scanned_sources_match' => $issues === [],
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }
}
