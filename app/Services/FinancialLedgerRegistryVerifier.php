<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Verify registered subscriber-ledger legs against actual persisted sources.
 * Internal read-only adapter; caller must own the scope-first transaction.
 * Registry consistency and verified registered legs do not establish that
 * every actual business effect was registered. Never authorize closure here.
 */
final class FinancialLedgerRegistryVerifier
{
    public function __construct(
        private FinancialPostingRegistry $registry,
        private FinancialLedgerEvidence $ledger,
    ) {}

    public function verifyPeriod(string $scope, int $periodId): array
    {
        // Validates scope, period, operation payloads and canonical event bytes,
        // holding scope/period locks before any source rows are examined.
        $result = $this->registry->verifyPeriod($scope, $periodId);
        $verified = 0;
        $unverified = 0;
        $query = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as op', 'op.operation_uuid', '=', 'e.operation_uuid')
            ->where('op.period_id', $periodId)
            ->select('e.*')->lockForUpdate();

        foreach ($query->lazyById(250, 'e.id', 'id') as $event) {
            if ($event->scope_key !== $scope) {
                throw new DomainException('ledger_registry_scope_mismatch');
            }
            if ($event->source_type !== 'ledger_transaction') {
                $unverified++;
                continue;
            }
            $id = $event->source_id;
            if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
                || (string) (int) $id !== $id) {
                throw new DomainException('ledger_registry_source_identity');
            }
            $actual = $this->ledger->leg((int) $id);
            if ($actual['source_type'] !== $event->source_type
                || $actual['source_id'] !== $id
                || $actual['leg'] !== $event->leg
                || (string) $actual['amount_minor'] !== (string) $event->amount_minor
                || $actual['currency'] !== $event->currency
                || ! hash_equals($actual['source_hash'], $event->source_hash)) {
                throw new DomainException('ledger_registry_source_mismatch');
            }
            $verified++;
        }
        if ($verified + $unverified !== $result['events']) {
            throw new DomainException('ledger_registry_event_count_mismatch');
        }
        return $result + [
            'registered_ledger_legs_verified' => $verified,
            'other_registered_legs_unverified' => $unverified,
        ];
    }
}
