<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Inventory actual runtime switches and installed protection. An enabled
 * candidate switch is not proof of exclusive entry-point coverage, privilege
 * isolation or concurrency. Never certify that by configuration alone.
 */
final class FinancialWriterCoverageDiagnostic
{
    private const FLAGS = [
        'invoices' => 'invoice_and_reading_approval',
        'cash_receipts' => 'cash_receipt',
        'void_api' => 'receipt_void',
        'subscriber_transfers' => 'subscriber_transfer',
        'treasury_opening_funding' => 'treasury_opening_and_funding',
        'treasury_transfers' => 'treasury_dispatch_and_receipt',
        'cash_handovers' => 'collector_handover',
        'refunds' => 'refund',
        'completed_cash_refund' => 'completed_cash_refund',
        'bank_fees' => 'bank_fee',
        'bank_evidence' => 'bank_statement_and_manual_matching',
        'cash_count_adjustments' => 'cash_count_adjustment',
        'late_cash_settlements' => 'late_cash_and_counted_cash',
        'noncash_settlements' => 'noncash_settlement',
        'active_held_release' => 'held_cash_release',
    ];

    public function inspect(): array
    {
        if (DB::transactionLevel() !== 1) throw new DomainException('writer_coverage_requires_locked_transaction');
        DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
        $writers = [];
        foreach (self::FLAGS as $flag => $family) {
            $writers[] = ['family' => $family, 'flag' => $flag,
                'candidate_enabled' => config('financial_candidate.'.$flag) === true,
                'exclusive_entrypoints_verified' => false];
        }
        $guards = [];
        $checks = [
            'ledger' => fn () => app(LedgerProtection::class)->active(),
            'source_period' => fn () => app(FinancialSourceInsertProtection::class)->active(),
            'registry' => fn () => app(FinancialPostingRegistryProtection::class)->active(),
            'close_journal' => fn () => app(FinancialCloseJournalProtection::class)->active(),
            'allocation_history' => fn () => app(AllocationHistoryProtection::class)->active(),
            'held_documents' => fn () => app(HeldCashDocumentProtection::class)->active(),
            'held_release' => fn () => app(HeldCashReleaseDocumentProtection::class)->active(),
            'held_release_journal' => fn () => app(HeldCashReleaseDocumentProtection::class)->journalActive(),
            'held_reinstatement' => fn () => app(HeldCashReleaseDocumentProtection::class)->reinstatementActive(),
            'held_active_release' => fn () => app(ActiveHeldCashRelease::class)->protectionActive(),
            'financial_approval_audit' => fn () => app(FinancialApprovalAuditProtection::class)->active(),
            'custody_shortage_decisions' => fn () => app(CustodyShortageDecisionProtection::class)->active(),
            'custody_shortage_recovery_proposals' => fn () => app(CustodyShortageRecoveryProposalProtection::class)->active(),
            'custody_shortage_recoveries' => fn () => app(CustodyShortageRecoveryProtection::class)->active(),
            'custody_shortage_period' => fn () => app(CustodyShortagePeriodProtection::class)->active(),
            'completed_refund_request' => fn () => app(CompletedCashRefundRequestProtection::class)->active(),
            'completed_refund_decision' => fn () => app(CompletedCashRefundDecisionProtection::class)->active(),
            'completed_refund_resolution_proposal' => fn () => app(CompletedCashRefundResolutionProposalProtection::class)->active(),
            'completed_refund_resolution_approval' => fn () => app(CompletedCashRefundResolutionApprovalProtection::class)->active(),
            'completed_refund_resolution_revision' => fn () => app(CompletedCashRefundResolutionRevisionProtection::class)->active(),
            'completed_refund_batch_approval' => fn () => app(CompletedCashRefundBatchApprovalProtection::class)->active(),
            'completed_refund_document' => fn () => app(CompletedCashRefundDocumentProtection::class)->active(),
            'completed_refund_source' => fn () => app(CompletedCashRefundSourceProtection::class)->active(),
            'correction_documents' => fn () => app(FinancialCorrectionDocumentProtection::class)->active(),
            'registered_documents' => fn () => app(RegisteredDocumentProtection::class)->active(),
            'invoice_projection' => fn () => app(InvoiceProjectionProtection::class)->active(),
            'receipt_void' => fn () => app(ReceiptVoidProtection::class)->active(),
            'receipt_request' => fn () => app(ReceiptVoidRequestProtection::class)->active(),
        ];
        foreach ($checks as $key => $check) {
            try {
                $guards[$key] = $check() === true;
            } catch (\Throwable) {
                // Preserve the failed check without exposing database details.
                $guards[$key] = false;
            }
        }
        try {
            $grantReport = app(RuntimeDatabasePrivileges::class)->inspect();
        } catch (\Throwable) {
            $grantReport = ['accepted' => false, 'issues' => ['runtime_grants_unverifiable']];
        }
        return ['writers' => $writers, 'guards' => $guards,
            'all_candidate_switches_enabled' => ! in_array(false, array_column($writers, 'candidate_enabled'), true),
            'listed_guards_active' => ! in_array(false, $guards, true),
            'runtime_grant_allowlist_accepted' => $grantReport['accepted'],
            'runtime_grant_issues' => $grantReport['issues'],
            'exclusive_entrypoints_verified' => false, 'runtime_privileges_verified' => false,
            'complete_writer_coverage_verified' => false, 'closure_authorized' => false];
    }
}
