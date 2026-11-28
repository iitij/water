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
        'collection_evidence' => 'collection_claim_submission_and_rejection',
        'cash_session_lifecycle' => 'cash_session_open_close_decision',
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

    // Directly gated families are independent of optional candidate flags.
    // Guard presence is a prerequisite, not exclusive entry-point proof.
    private const DIRECT = [
        'held_cash_assignment' => ['held_documents', 'held_cash_destinations', 'held_registry_links'],
        'held_cash_payout_authorization' => ['held_documents', 'held_cash_destinations', 'held_registry_links'],
        'held_cash_payout_cancellation' => ['held_documents', 'held_cash_destinations', 'held_registry_links'],
        'held_cash_payout' => ['held_documents', 'held_cash_destinations', 'held_registry_links'],
        'held_cash_credit' => ['held_documents', 'held_cash_destinations', 'held_registry_links', 'ledger', 'allocation_history'],
        'custody_shortage_recovery_proposal' => ['custody_shortage_recovery_proposals', 'custody_shortage_period'],
        'custody_shortage_recovery_approval' => ['custody_shortage_recovery_proposals', 'custody_shortage_recoveries',
            'custody_shortage_period', 'source_period', 'dated_source_destinations', 'session_transfer_destinations', 'registry', 'registry_source_identity'],
        'custody_shortage_loss_proposal_and_approval' => ['custody_shortage_decisions', 'custody_shortage_period'],
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
        $guards = app(FinancialGuardInventory::class)->inspect();
        $direct = $this->directWriterInventory($guards);
        try {
            $grantReport = app(RuntimeDatabasePrivileges::class)->inspect();
        } catch (\Throwable) {
            $grantReport = ['accepted' => false, 'issues' => ['runtime_grants_unverifiable']];
        }
        return ['writers' => $writers, 'direct_writers' => $direct, 'guards' => $guards,
            'listed_direct_writer_guards_active' => ! in_array(false, array_column($direct, 'listed_guards_active'), true),
            'all_candidate_switches_enabled' => ! in_array(false, array_column($writers, 'candidate_enabled'), true),
            'listed_guards_active' => ! in_array(false, $guards, true),
            'runtime_grant_allowlist_accepted' => $grantReport['accepted'],
            'runtime_grant_issues' => $grantReport['issues'],
            'runtime_close_control_write_isolated' => $grantReport['close_control_write_isolated'] ?? false,
            'runtime_close_control_issues' => $grantReport['close_control_issues'] ?? ['runtime_grants_unverifiable'],
            'exclusive_entrypoints_verified' => false, 'runtime_privileges_verified' => false,
            'complete_writer_coverage_verified' => false, 'closure_authorized' => false];
    }

    /** Missing or non-boolean guard results fail closed. */
    public function directWriterInventory(array $guards): array
    {
        $rows = [];
        foreach (self::DIRECT as $family => $required) {
            $missing = array_values(array_filter($required, static fn ($guard) => ($guards[$guard] ?? false) !== true));
            $rows[] = ['family' => $family, 'gate_type' => 'direct_period_gate',
                'required_guards' => $required, 'missing_guards' => $missing,
                'listed_guards_active' => $missing === [],
                'exclusive_entrypoints_verified' => false];
        }
        return $rows;
    }
}
