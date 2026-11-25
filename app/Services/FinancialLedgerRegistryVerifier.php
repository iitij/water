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
    ) {}

    public function verifyPeriod(string $scope, int $periodId): array
    {
        // Validates scope, period, operation payloads and canonical event bytes,
        // holding scope/period locks before any source rows are examined.
        $result = $this->registry->verifyPeriod($scope, $periodId);
        $verified = 0;
        $unverified = 0;
        $treasuryVerified = 0;
        // Treasury writers acquire the issuance lock before business rows.
        // Obtain it before reading any source, even in a mixed registry.
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
            if (! in_array($event->source_type, ['ledger_transaction', 'treasury_posting'], true)) {
                $unverified++;
                continue;
            }
            $id = $event->source_id;
            if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
                || (string) (int) $id !== $id) {
                throw new DomainException('ledger_registry_source_identity');
            }
            $isTreasury = $event->source_type === 'treasury_posting';
            if ($isTreasury) {
                $posting = DB::table('treasury_postings')->where('id', (int) $id)->lockForUpdate()->first();
                if (! $posting) {
                    throw new DomainException('treasury_registry_source_missing');
                }
                // Even an unsupported treasury family cannot be assigned to a
                // different effective date. No timestamp-derived period guesses.
                if ($posting->effective_on !== $event->operation_effective_on) {
                    throw new DomainException('treasury_registry_effective_date_mismatch');
                }
                if (! in_array($posting->kind, ['internal_transfer_out', 'internal_transfer_in'], true)) {
                    $unverified++;
                    continue;
                }
                $actual = $this->treasury->leg((int) $id);
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
            if ($isTreasury) {
                $treasuryVerified++;
            } else {
                $verified++;
            }
        }
        if ($verified + $treasuryVerified + $unverified !== $result['events']) {
            throw new DomainException('ledger_registry_event_count_mismatch');
        }
        return $result + [
            'registered_ledger_legs_verified' => $verified,
            'registered_treasury_legs_verified' => $treasuryVerified,
            'other_registered_legs_unverified' => $unverified,
        ];
    }
}
