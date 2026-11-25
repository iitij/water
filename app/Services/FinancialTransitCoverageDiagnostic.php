<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\SignedMinor;
use App\Support\TreasuryMoney;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Source-backed transit diagnostic at an explicit boundary. Not a certified
 * opening or closure source. Old unregistered dispatches are not adopted.
 */
final class FinancialTransitCoverageDiagnostic
{
    public function __construct(
        private FinancialPostingRegistry $registry,
        private TreasuryTransferEvidence $evidence,
    ) {}

    public function inspect(int $periodId, string $through): array
    {
        $this->need(DB::transactionLevel() > 0, 'transit_requires_gate');
        $this->registry->verifyPeriod('water', $periodId);
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->first();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $through, new DateTimeZone('Asia/Aden'));
        $this->need($period !== null && $date !== false && $date->format('Y-m-d') === $through
            && $through >= $period->start_on && $through < $period->next_on
            && $through <= now('Asia/Aden')->toDateString(), 'transit_boundary');
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'transit_issuance_lock');
        $verified = [$periodId => true];
        $rows = [];
        $issues = [];
        $totals = ['opening_minor' => 0, 'dispatch_minor' => 0, 'receipt_minor' => 0, 'closing_minor' => 0];
        foreach (DB::table('treasury_transfers')->where('dispatched_on', '<=', $through)
            ->orderBy('id')->lockForUpdate()->lazyById(200) as $transfer) {
            $id = (int) $transfer->id;
            $receipts = DB::table('treasury_transfer_receipts')->where('treasury_transfer_id', $id)->lockForUpdate()->get();
            if ($receipts->count() === 1 && $transfer->dispatched_on < $period->start_on
                && $receipts[0]->received_on < $period->start_on
                && $receipts[0]->received_on >= $transfer->dispatched_on) {
                continue; // completed before the selected period; no retrospective audit
            }
            try {
                $this->need($receipts->count() <= 1, 'transit_duplicate_receipt');
                $receipt = $receipts->first();
                $amount = TreasuryMoney::document($transfer->amount_minor, true);
                $out = $this->prove($transfer, false, $verified);
                $in = null;
                if ($receipt !== null && $receipt->received_on <= $through) {
                    $in = $this->prove($receipt, true, $verified);
                    $this->need($receipt->received_on >= $transfer->dispatched_on
                        && $in['amount_minor'] === $amount, 'transit_receipt_conflict');
                }
                $this->need($out['amount_minor'] === -$amount, 'transit_dispatch_conflict');
                $opening = $transfer->dispatched_on < $period->start_on ? $amount : 0;
                $dispatched = $opening === 0 ? $amount : 0;
                $received = $in === null ? 0 : $amount;
                $closing = SignedMinor::subtract(SignedMinor::add($opening, $dispatched), $received);
                $this->need($closing === 0 || $closing === $amount, 'transit_conservation');
                $row = ['transfer_id' => $id, 'from_account_id' => (int) $transfer->from_account_id,
                    'to_account_id' => (int) $transfer->to_account_id, 'dispatched_on' => $transfer->dispatched_on,
                    'received_on' => $in === null ? null : $receipt->received_on,
                    'opening_minor' => $opening, 'dispatch_minor' => $dispatched,
                    'receipt_minor' => $received, 'closing_minor' => $closing,
                    'dispatch_operation_uuid' => $out['uuid'], 'receipt_operation_uuid' => $in['uuid'] ?? null,
                    'dispatch_source_hash' => $out['hash'], 'receipt_source_hash' => $in['hash'] ?? null];
                foreach (array_keys($totals) as $key) $totals[$key] = SignedMinor::add($totals[$key], $row[$key]);
                $rows[] = $row;
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'transfer_id' => $id];
            }
        }
        // Totals deliberately cover ONLY verified rows, and must not be used
        // as certified balances when even one transfer remains unresolved.
        return ['period_id' => $periodId, 'through' => $through, 'rows' => $rows,
            'verified_row_totals' => $totals, 'issues' => $issues,
            'scanned_transfers_match' => $issues === [], 'opening_certified' => false,
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    private function prove(object $doc, bool $receipt, array &$verified): array
    {
        $column = $receipt ? 'treasury_transfer_receipt_id' : 'treasury_transfer_id';
        $kind = $receipt ? 'internal_transfer_in' : 'internal_transfer_out';
        $date = $receipt ? $doc->received_on : $doc->dispatched_on;
        $actor = $receipt ? $doc->received_by : $doc->dispatched_by;
        $postings = DB::table('treasury_postings')->where($column, $doc->id)->lockForUpdate()->get();
        $this->need($postings->count() === 1 && $postings[0]->kind === $kind
            && $postings[0]->effective_on === $date, 'transit_posting_missing_or_conflicting');
        $posting = $postings[0];
        $leg = $this->evidence->leg((int) $posting->id);
        $events = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', 'treasury_posting')->where('source_id', (string) $posting->id)
            ->where('leg', 'treasury')->lockForUpdate()->get();
        $this->need($events->count() === 1 && (string) $events[0]->amount_minor === (string) $leg['amount_minor']
            && $events[0]->currency === $leg['currency'] && hash_equals($leg['source_hash'], $events[0]->source_hash),
            'transit_registry_leg_missing_or_conflicting');
        $event = $events[0];
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date
            && DB::table('financial_posting_events')->where('operation_uuid', $event->operation_uuid)->count() === 1,
            'transit_registry_operation_conflict');
        $id = (int) $operation->period_id;
        if (! isset($verified[$id])) {
            $this->registry->verifyPeriod('water', $id);
            $verified[$id] = true;
        }
        $audit = AuditEvent::query()->where('event', $receipt ? 'treasury.transfer_received' : 'treasury.transfer_dispatched')
            ->where('entity_type', $receipt ? 'treasury_transfer_receipt' : 'treasury_transfer')
            ->where('entity_id', (string) $doc->id)->lockForUpdate()->get();
        $this->need($audit->count() === 1 && (int) $audit[0]->actor_user_id === (int) $actor, 'transit_audit_missing_or_conflicting');
        $after = $audit[0]->after;
        $fields = ['amount_minor', 'reference', 'evidence', $receipt ? 'received_on' : 'dispatched_on',
            $receipt ? 'received_by' : 'dispatched_by'];
        $fields = array_merge($fields, $receipt ? ['treasury_transfer_id'] : ['from_account_id', 'to_account_id']);
        foreach ($fields as $field) {
            $this->need(array_key_exists($field, $after) && (string) $after[$field] === (string) $doc->$field, 'transit_audit_missing_or_conflicting');
        }
        $this->need((int) ($after['posting_id'] ?? 0) === (int) $posting->id
            && (string) ($after['in_transit_minor'] ?? '') === ($receipt ? '0' : (string) $doc->amount_minor),
            'transit_audit_missing_or_conflicting');
        return ['amount_minor' => $leg['amount_minor'], 'uuid' => $event->operation_uuid, 'hash' => $leg['source_hash']];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
