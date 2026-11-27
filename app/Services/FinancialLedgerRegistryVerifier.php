<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Verify registered subscriber-ledger and supported treasury legs against persisted sources.
 * Internal read-only adapter; caller must own the scope-first transaction.
 * Registry consistency and verified registered legs do not establish that
 * every actual business effect was registered. Never authorize closure here.
 */
final class FinancialLedgerRegistryVerifier
{
    public function __construct(
        private FinancialPostingRegistry $registry,
        private FinancialLedgerEvidence $ledger,
        private TreasuryTransferEvidence $treasury,
        private TreasuryCustodyEvidence $custody,
        private TreasuryOpeningFundingEvidence $openingFunding,
        private TreasuryAdjustmentEvidence $adjustment,
        private TreasuryCollectionEvidence $collection,
    ) {}

    public function verifyPeriod(string $scope, int $periodId): array
    {
        // Validates scope, period, operation payloads and canonical event bytes,
        // holding scope/period locks before any source rows are examined.
        $result = $this->registry->verifyPeriod($scope, $periodId);
        $verified = 0;
        $unverified = 0;
        $unverifiedSources = [];
        $treasuryVerified = 0;
        $openingVerified = 0;
        $heldVerified = 0;
        $heldProof = false;
        // Scope-first isolated verification only. Legacy subscriber-first
        // writers can invert this lock order; do not activate concurrently
        // with unfenced writers before a reviewed lock protocol exists.
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('ledger_registry_issuance_lock_missing');
        }
        $query = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as op', 'op.operation_uuid', '=', 'e.operation_uuid')
            ->where('op.period_id', $periodId)
            ->select('e.*', 'op.effective_on as operation_effective_on')->lockForUpdate();

        foreach ($query->lazyById(250, 'e.id', 'id') as $event) {
            if ($event->scope_key !== $scope) {
                throw new DomainException('ledger_registry_scope_mismatch');
            }
            if (in_array($event->source_type, ['held_cash_assignment', 'held_cash_payout', 'held_cash_credit'], true)) {
                if (! $heldProof) {
                    app(TreasuryHeldCashLiabilityService::class)->verifyCoverage();
                    $heldProof = true;
                }
                $heldVerified++;
                continue;
            }
            if (! in_array($event->source_type, ['ledger_transaction', 'treasury_posting', 'treasury_opening'], true)) {
                $unverified++;
                $unverifiedSources[] = ['source_type' => $event->source_type, 'source_id' => $event->source_id,
                    'leg' => $event->leg, 'reason' => 'unsupported_source_type'];
                continue;
            }
            $id = $event->source_id;
            if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
                || (string) (int) $id !== $id) {
                throw new DomainException('ledger_registry_source_identity');
            }
            $isTreasury = $event->source_type === 'treasury_posting';
            $isOpening = $event->source_type === 'treasury_opening';
            if ($isOpening) {
                $actual = $this->openingFunding->opening((int) $id, $event->operation_effective_on);
            } elseif ($isTreasury) {
                $posting = DB::table('treasury_postings')->where('id', (int) $id)->lockForUpdate()->first();
                if (! $posting) {
                    throw new DomainException('treasury_registry_source_missing');
                }
                // Even an unsupported treasury family cannot be assigned to a
                // different effective date. No timestamp-derived period guesses.
                if ($posting->effective_on !== $event->operation_effective_on) {
                    throw new DomainException('treasury_registry_effective_date_mismatch');
                }
                if (in_array($posting->kind, ['internal_transfer_out', 'internal_transfer_in'], true)) {
                    $actual = $this->treasury->leg((int) $id);
                } elseif (in_array($posting->kind, ['cash_handover', 'payment_refund'], true)) {
                    $actual = $this->custody->leg((int) $id);
                } elseif ($posting->kind === 'collector_funding') {
                    $actual = $this->openingFunding->funding((int) $id, $event->operation_effective_on);
                } elseif ($posting->kind === 'custody_shortage_recovery') {
                    $actual = app(CustodyShortageRecoveryWriter::class)->leg((int) $id);
                } elseif (in_array($posting->kind, ['bank_fee', 'cash_count_adjustment'], true)) {
                    $actual = $this->adjustment->leg((int) $id);
                } elseif (in_array($posting->kind, ['bank_collection', 'late_cash_receipt'], true)) {
                    $actual = $this->collection->leg((int) $id);
                } else {
                    $unverified++;
                    $unverifiedSources[] = ['source_type' => $event->source_type, 'source_id' => $event->source_id,
                        'leg' => $event->leg, 'reason' => 'unsupported_treasury_kind'];
                    continue;
                }
            } else {
                $actual = $this->ledger->leg((int) $id);
            }
            if ($actual['source_type'] !== $event->source_type
                || $actual['source_id'] !== $id
                || $actual['leg'] !== $event->leg
                || (string) $actual['amount_minor'] !== (string) $event->amount_minor
                || $actual['currency'] !== $event->currency
                || ! hash_equals($actual['source_hash'], $event->source_hash)) {
                throw new DomainException('ledger_registry_source_mismatch');
            }
            if ($isOpening) {
                $openingVerified++;
            } elseif ($isTreasury) {
                $treasuryVerified++;
            } else {
                $verified++;
            }
        }
        if ($verified + $treasuryVerified + $openingVerified + $heldVerified + $unverified !== $result['events']) {
            throw new DomainException('ledger_registry_event_count_mismatch');
        }
        return $result + [
            'registered_ledger_legs_verified' => $verified,
            'registered_treasury_legs_verified' => $treasuryVerified,
            'registered_opening_legs_verified' => $openingVerified,
            'registered_held_legs_verified' => $heldVerified,
            'other_registered_legs_unverified' => $unverified,
            'unverified_registered_sources' => $unverifiedSources,
        ];
    }
}
