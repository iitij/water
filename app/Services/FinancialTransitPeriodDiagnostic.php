<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Verify recorded transfers in transit across the selected period boundaries. */
final class FinancialTransitPeriodDiagnostic
{
    public function __construct(
        private TreasuryTransferEvidence $evidence,
        private FinancialPostingRegistry $registry,
    ) {}

    /** The close diagnostic already holds the scope and issuance locks. */
    public function inspect(string $startOn, string $nextOn): array
    {
        if (DB::transactionLevel() !== 1) {
            throw new DomainException('transit_period_requires_locked_transaction');
        }

        $transfers = DB::table('treasury_transfers as t')
            ->leftJoin('treasury_transfer_receipts as r', 'r.treasury_transfer_id', '=', 't.id')
            ->where('t.dispatched_on', '<', $nextOn)
            ->where(fn ($q) => $q->where('t.dispatched_on', '>=', $startOn)
                ->orWhereNull('r.id')->orWhere('r.received_on', '>=', $startOn))
            ->select('t.*', 'r.id as receipt_id', 'r.received_on')
            ->orderBy('t.id')->lockForUpdate()->get();

        $verifiedPeriods = [];
        $rows = [];
        $issues = [];
        $totals = ['opening_minor' => 0, 'dispatched_minor' => 0, 'received_minor' => 0, 'closing_minor' => 0];
        foreach ($transfers as $transfer) {
            try {
                $dispatch = $this->postingEvidence('treasury_transfer_id', (int) $transfer->id,
                    'internal_transfer_out', $transfer->dispatched_on, $verifiedPeriods);
                $receivedInPeriod = $transfer->receipt_id !== null && $transfer->received_on < $nextOn;
                $receipt = $receivedInPeriod ? $this->postingEvidence('treasury_transfer_receipt_id', (int) $transfer->receipt_id,
                    'internal_transfer_in', $transfer->received_on, $verifiedPeriods) : null;
            } catch (DomainException $error) {
                $issues[] = ['transfer_id' => (string) $transfer->id, 'code' => $error->getMessage()];
                continue;
            }

            $amount = SignedMinor::negate($dispatch['amount_minor']);
            $priorDispatch = $transfer->dispatched_on < $startOn;
            $values = [
                'opening_minor' => $priorDispatch ? $amount : 0,
                'dispatched_minor' => $priorDispatch ? 0 : $amount,
                'received_minor' => $receipt === null ? 0 : $amount,
                'closing_minor' => $receipt === null ? $amount : 0,
            ];
            foreach ($values as $field => $minor) {
                $totals[$field] = SignedMinor::add($totals[$field], $minor);
            }
            $rows[] = [
                'transfer_id' => (string) $transfer->id,
                'from_account_id' => (string) $transfer->from_account_id,
                'to_account_id' => (string) $transfer->to_account_id,
                'dispatched_on' => $transfer->dispatched_on,
                'received_on' => $receipt === null ? null : $transfer->received_on,
                'dispatch_evidence' => $dispatch,
                'receipt_evidence' => $receipt,
            ] + $values;
        }

        if ($issues !== []) {
            return ['matched' => false, 'issues' => $issues, 'totals' => null, 'rows' => []];
        }

        return ['matched' => true, 'issues' => [], 'totals' => $totals, 'rows' => $rows];
    }

    private function postingEvidence(string $foreignKey, int $sourceId, string $kind, string $date, array &$verifiedPeriods): array
    {
        $posting = DB::table('treasury_postings')->where($foreignKey, $sourceId)->lockForUpdate()->first();
        if ($posting === null || $posting->kind !== $kind || $posting->effective_on !== $date) {
            throw new DomainException('transit_posting_missing_or_conflicting');
        }
        $proof = $this->evidence->leg((int) $posting->id);
        $event = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('e.scope_key', 'water')->where('o.scope_key', 'water')->where('e.source_type', $proof['source_type'])
            ->where('e.source_id', $proof['source_id'])->where('e.leg', $proof['leg'])
            ->select('e.*', 'o.period_id', 'o.effective_on')->lockForUpdate()->first();
        if ($event === null || $event->effective_on !== $date || SignedMinor::parse($event->amount_minor) !== $proof['amount_minor']
            || $event->currency !== $proof['currency'] || ! hash_equals($proof['source_hash'], $event->source_hash)) {
            throw new DomainException('transit_posting_unregistered_or_conflicting');
        }

        if (! isset($verifiedPeriods[$event->period_id])) {
            $this->registry->verifyPeriod('water', (int) $event->period_id);
            $verifiedPeriods[$event->period_id] = true;
        }

        return ['posting_id' => $proof['source_id'], 'operation_uuid' => $event->operation_uuid,
            'source_hash' => $proof['source_hash'], 'amount_minor' => $proof['amount_minor']];
    }
}
