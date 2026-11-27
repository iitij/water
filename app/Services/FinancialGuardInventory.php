<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Shared read-only inventory. Guard presence is not writer exclusivity. */
final class FinancialGuardInventory
{
    public function inspect(): array
    {
        $guards = [];
        $checks = [
            'connection_constraints' => fn () => app(FinancialConnectionIntegrity::class)->active(),
            'live_metadata_routine' => fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                || (config('database.financial_trigger_metadata_routine') === true
                    ? FinancialTriggerMetadata::query()->isNotEmpty()
                    : app(FinancialTriggerMetadata::class)->deploymentVerified()),
            'ledger' => fn () => app(LedgerProtection::class)->active(),
            'source_period' => fn () => app(FinancialSourceInsertProtection::class)->active(),
            'observation_graph_freeze' => fn () => app(ObservationGraphFreezeProtection::class)->active(),
            'collection_evidence_audit' => fn () => app(CollectionEvidenceAuditProtection::class)->active(),
            'collection_evidence_period' => fn () => app(CollectionEvidencePeriodProtection::class)->active(),
            'bank_evidence_period' => fn () => app(BankEvidencePeriodProtection::class)->active(),
            'cash_count_period' => fn () => app(CashCountPeriodProtection::class)->active(),
            'registry' => fn () => app(FinancialPostingRegistryProtection::class)->active(),
            'close_journal' => fn () => app(FinancialCloseJournalProtection::class)->active(),
            'allocation_history' => fn () => app(AllocationHistoryProtection::class)->active(),
            'held_documents' => fn () => app(HeldCashDocumentProtection::class)->active(),
            'refund_release_period' => fn () => app(RefundReleasePeriodProtection::class)->active(),
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
        return $guards;
    }
}
