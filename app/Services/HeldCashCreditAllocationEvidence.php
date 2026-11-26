<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscriber;
use App\Models\TreasuryHeldCashCredit;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only candidate proof of current allocation of originally held cash.
 * Never grants spending authority: later invoice reversals can restore credit.
 * Only call under an isolated scope-first transaction.
 */
final class HeldCashCreditAllocationEvidence
{
    public function inspectAccount(int $accountId): array
    {
        $this->need(DB::transactionLevel() > 0, 'held_credit_allocation_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && $scope->currency === 'YER',
            'held_credit_allocation_requires_gate');
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'held_credit_allocation_requires_gate');
        $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $this->need($account !== null && $account->channel === 'cash', 'held_credit_allocation_account');
        app(TreasuryHeldCashLiabilityService::class)->verifyCoverage();
        $ids = DB::table('treasury_held_cash_credits as c')
            ->join('treasury_held_cash_assignments as a', 'a.operation_uuid', '=', 'c.assignment_uuid')
            ->join('treasury_held_cash_liabilities as l', 'l.id', '=', 'a.liability_id')
            ->join('treasury_cash_adjustments as d', 'd.id', '=', 'l.treasury_cash_adjustment_id')
            ->where('d.treasury_account_id', $accountId)->orderBy('c.id')->lockForUpdate()->pluck('c.id');
        $documents = TreasuryHeldCashCredit::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $total = $applied = $unapplied = 0;
        $proofs = [];
        foreach ($documents->groupBy('subscriber_id')->sortKeys() as $subscriberId => $credits) {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
            $audit = app(FinancialReconciliationService::class)->inspect($subscriber);
            $this->need($audit['matches'], 'held_credit_allocation_reconciliation');
            $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriberId)
                ->orderByDesc('revision')->lockForUpdate()->first();
            $this->need($revision !== null && app(AllocationSnapshotIntegrity::class)->valid($revision),
                'held_credit_allocation_revision');
            foreach ($credits as $credit) {
                $amount = AccountMoney::stored($credit);
                $allocated = $remaining = 0;
                $rows = DB::table('payment_allocations')->where('revision_id', $revision->id)
                    ->where('held_cash_credit_id', $credit->id)->orderBy('position')->lockForUpdate()->get();
                foreach ($rows as $row) {
                    $minor = AccountMoney::positiveAllocation($row->amount_minor);
                    $this->need($row->payment_id === null && $row->account_transfer_id === null, 'held_credit_allocation_source');
                    if ($row->invoice_id === null) {
                        $remaining = SignedMinor::add($remaining, $minor);
                    } else {
                        $invoice = \App\Models\Invoice::query()->lockForUpdate()->find($row->invoice_id);
                        $this->need($invoice !== null && (int) $invoice->subscriber_id === (int) $subscriberId
                            && ! in_array($invoice->status, ['draft', 'cancelled'], true), 'held_credit_allocation_invoice');
                        $allocated = SignedMinor::add($allocated, $minor);
                    }
                }
                $this->need($amount > 0 && SignedMinor::add($allocated, $remaining) === $amount,
                    'held_credit_allocation_conservation');
                $total = SignedMinor::add($total, $amount);
                $applied = SignedMinor::add($applied, $allocated);
                $unapplied = SignedMinor::add($unapplied, $remaining);
                $proofs[] = ['credit_id' => (int) $credit->id, 'subscriber_id' => (int) $subscriberId,
                    'revision_id' => (int) $revision->id, 'allocation_hash' => $revision->allocation_hash,
                    'amount_minor' => $amount, 'applied_minor' => $allocated, 'unapplied_minor' => $remaining];
            }
        }
        $reserve = app(HeldCashReservation::class)->subscriberCreditReserveForAccount($accountId);
        $this->need($total === $reserve && SignedMinor::add($applied, $unapplied) === $total,
            'held_credit_allocation_conservation');
        return ['account_id' => $accountId, 'credited_minor' => $total, 'applied_minor' => $applied,
            'unapplied_minor' => $unapplied, 'protected_minor' => $reserve, 'credits' => $proofs,
            'spending_release_authorized' => false, 'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
