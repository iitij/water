<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only cutoff reconciliation of recorded prospective subscriber sources.
 * Existing accounts with a prior balance need independent opening evidence.
 * Allocation membership/conservation is not independent target/FIFO replay.
 */
final class FinancialSubscriberPeriodEvidence
{
    public function __construct(private FinancialSourceCoverageDiagnostic $sources,
        private AllocationSnapshotIntegrity $integrity) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('subscriber_cutoff_requires_gate');
        DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($period->start_on, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($period->next_on, $zone))->setTimezone($utc);
        $until = $end->format('Y-m-d H:i:s');
        $base = ['period_id' => $periodId, 'period_ended' => now('Asia/Aden')->toDateString() >= $period->next_on,
            'opening_continuity_verified' => false, 'allocation_policy_verified' => false, 'closure_authorized' => false];
        if (! $base['period_ended']) return $base + ['scanned_cutoff_accounts_match' => false, 'rows' => [], 'issues' => [], 'prior_accounts' => []];
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('subscriber_cutoff_issuance_lock');
        }
        $source = $this->sources->inspect($periodId);
        $issues = $rows = $prior = $byAccount = [];
        if (! $source['scanned_sources_match'] || ! app(AllocationHistoryProtection::class)->active()) {
            return $base + ['scanned_cutoff_accounts_match' => false, 'rows' => [], 'prior_accounts' => [],
                'issues' => [['code' => 'subscriber_cutoff_source_or_guard_unverified']]];
        }
        $events = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('o.period_id', $periodId)->where('e.source_type', 'ledger_transaction')
            ->where('e.leg', 'subscriber')->select('e.*', 'o.effective_on', 'o.recorded_at_utc')
            ->orderBy('e.id')->lockForUpdate()->get();
        foreach ($events as $event) {
            $entry = DB::table('transactions')->where('id', $event->source_id)->lockForUpdate()->sole();
            $id = (int) $entry->subscriber_id;
            $byAccount[$id][] = [$event, $entry];
        }
        foreach (DB::table('subscribers')->where('created_at', '<', $until)->orderBy('id')->lockForUpdate()->get() as $account) {
            $id = (int) $account->id;
            $legs = $byAccount[$id] ?? [];
            unset($byAccount[$id]);
            if ($account->created_at < $start) {
                $prior[] = $id;
                continue;
            }
            try {
                if ($account->deleted_at !== null && $account->deleted_at < $until) {
                    throw new DomainException('subscriber_cutoff_deleted_account');
                }
                $invoices = $credits = $hashes = $ids = [];
                foreach ($legs as [$event, $entry]) {
                    if (new DateTimeImmutable($event->recorded_at_utc, $utc) >= $end
                        || $entry->created_at < $start || $entry->created_at >= $until) {
                        throw new DomainException('subscriber_cutoff_recorded_outside_period');
                    }
                    $minor = SignedMinor::parse($event->amount_minor);
                    $ref = (int) $entry->reference_id;
                    $ids[] = (int) $entry->id;
                    $hashes[] = [$event->operation_uuid, $event->source_hash];
                    switch ($entry->type) {
                        case 'invoice':
                        case 'invoice_reversal':
                            $invoices[$ref] = SignedMinor::subtract($invoices[$ref] ?? 0, $minor);
                            break;
                        case 'payment':
                        case 'payment_reversal':
                            $key = 'payment:'.$ref;
                            $credits[$key] = SignedMinor::add($credits[$key] ?? 0, $minor);
                            break;
                        case 'payment_refund':
                            $refund = DB::table('payment_refunds')->where('id', $ref)->lockForUpdate()->sole();
                            $key = 'payment:'.$refund->payment_id;
                            $credits[$key] = SignedMinor::add($credits[$key] ?? 0, $minor);
                            break;
                        case 'payment_transfer_in':
                        case 'held_cash_credit':
                            $key = ($entry->type === 'payment_transfer_in' ? 'transfer:' : 'held:').$ref;
                            $credits[$key] = SignedMinor::add($credits[$key] ?? 0, $minor);
                            break;
                        default:
                            throw new DomainException('subscriber_cutoff_source_unsupported');
                    }
                }
                $observed = DB::table('transactions')->where('subscriber_id', $id)->where('created_at', '<', $until)
                    ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($value) => (int) $value)->all();
                sort($ids, SORT_NUMERIC);
                if ($ids !== $observed || DB::table('payment_allocation_revisions')->where('subscriber_id', $id)
                    ->where('created_at', '<', $start)->lockForUpdate()->first()) {
                    throw new DomainException('subscriber_cutoff_prior_or_unregistered_effect');
                }
                foreach (array_merge(array_values($invoices), array_values($credits)) as $minor) {
                    if ($minor < 0) throw new DomainException('subscriber_cutoff_negative_document');
                }
                $billed = SignedMinor::sum($invoices);
                $available = SignedMinor::sum($credits);
                $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $id)
                    ->where('created_at', '<', $until)->orderByDesc('revision')->lockForUpdate()->first();
                $paid = $unallocated = 0;
                if ($revision) {
                    if (! $this->integrity->valid($revision)) throw new DomainException('subscriber_cutoff_revision_integrity');
                    $bySource = array_fill_keys(array_keys($credits), 0);
                    $byInvoice = array_fill_keys(array_keys($invoices), 0);
                    foreach (DB::table('payment_allocations')->where('revision_id', $revision->id)
                        ->orderBy('position')->lockForUpdate()->get() as $allocation) {
                        $minor = SignedMinor::parseUnsigned($allocation->amount_minor);
                        $key = $allocation->payment_id !== null ? 'payment:'.$allocation->payment_id
                            : ($allocation->account_transfer_id !== null ? 'transfer:'.$allocation->account_transfer_id : 'held:'.$allocation->held_cash_credit_id);
                        if (! array_key_exists($key, $credits)) throw new DomainException('subscriber_cutoff_allocation_source');
                        $bySource[$key] = SignedMinor::add($bySource[$key], $minor);
                        if ($allocation->invoice_id === null) {
                            $unallocated = SignedMinor::add($unallocated, $minor);
                        } else {
                            $invoiceId = (int) $allocation->invoice_id;
                            if (! array_key_exists($invoiceId, $invoices)) throw new DomainException('subscriber_cutoff_allocation_invoice');
                            $byInvoice[$invoiceId] = SignedMinor::add($byInvoice[$invoiceId], $minor);
                            $paid = SignedMinor::add($paid, $minor);
                        }
                    }
                    if ($bySource !== $credits) throw new DomainException('subscriber_cutoff_allocation_source_total');
                    foreach ($byInvoice as $invoiceId => $minor) {
                        if ($minor > $invoices[$invoiceId]) throw new DomainException('subscriber_cutoff_allocation_invoice_cap');
                    }
                } elseif ($legs !== []) {
                    throw new DomainException('subscriber_cutoff_revision_missing');
                }
                if ($paid !== min($billed, $available) || SignedMinor::add($paid, $unallocated) !== $available) {
                    throw new DomainException('subscriber_cutoff_allocation_conservation');
                }
                $row = ['subscriber_id' => $id, 'billed_minor' => $billed, 'available_minor' => $available,
                    'allocated_minor' => $paid, 'unallocated_minor' => $unallocated,
                    'recorded_balance_minor' => SignedMinor::subtract($available, $billed),
                    'revision_id' => $revision ? (int) $revision->id : null,
                    'revision_hash' => $revision?->allocation_hash, 'registry_sources' => $hashes];
                $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                $rows[] = $row;
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'subscriber_id' => $id];
            }
        }
        foreach (array_keys($byAccount) as $id) $issues[] = ['code' => 'subscriber_cutoff_owner_not_present', 'subscriber_id' => $id];
        return $base + ['scanned_cutoff_accounts_match' => $issues === [], 'rows' => $rows,
            'issues' => $issues, 'prior_accounts' => $prior];
    }
}
