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
 * Independently replay target/FIFO from cutoff source amounts and stable document keys.
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
        if (! $base['period_ended']) return $base + ['scanned_cutoff_accounts_match' => false, 'rows' => [], 'issues' => [], 'prior_accounts' => [], 'prior_account_movements' => [], 'all_cutoff_accounts_match' => false];
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('subscriber_cutoff_issuance_lock');
        }
        $source = $this->sources->inspect($periodId);
        $issues = $rows = $prior = $priorMovements = $byAccount = [];
        if (! $source['scanned_sources_match'] || ! app(AllocationHistoryProtection::class)->active()
            || ! app(FinancialSourceInsertProtection::class)->active()
            || ! app(SubscriberBirthIdentityProtection::class)->active()) {
            return $base + ['scanned_cutoff_accounts_match' => false, 'rows' => [], 'prior_accounts' => [], 'prior_account_movements' => [], 'all_cutoff_accounts_match' => false,
                'issues' => [['code' => 'subscriber_cutoff_source_or_guard_unverified']]];
        }
        // SQL comparisons omit NULL birth timestamps entirely. Explicitly
        // inventory ambiguous records, regardless of their financial activity.
        $invalidBirth = DB::table('subscribers')->where(function ($query) {
            $query->whereNull('created_at');
            if (DB::getDriverName() === 'sqlite') {
                $query->orWhereRaw("strftime('%Y-%m-%d %H:%M:%S', created_at) IS NULL");
            } else {
                $query->orWhereRaw('YEAR(created_at) = 0');
            }
        })->orderBy('id')->lockForUpdate()->pluck('id');
        foreach ($invalidBirth as $id) {
            $issues[] = ['code' => 'subscriber_cutoff_birth_timestamp_unverified', 'subscriber_id' => (int) $id];
        }
        $predecessor = app(FinancialOpeningContinuityDiagnostic::class)->predecessor($periodId);
        $periods = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('start_on', '<=', $period->start_on)->orderBy('start_on')->lockForUpdate()->get();
        $coverage = [$periodId => true];
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
            if ($invalidBirth->contains($account->id)) continue;
            if ($account->created_at < $start) {
                $birthDate = (new DateTimeImmutable($account->created_at, $utc))->setTimezone($zone)->format('Y-m-d');
                $chain = $periods->filter(fn ($p) => $p->next_on > $birthDate)->values();
                if ($chain->isNotEmpty() && $chain[0]->start_on <= $birthDate) {
                    try {
                        $rows[] = $this->reconcileChain($account, $chain, $periodId, $legs, $coverage, $predecessor);
                    } catch (DomainException $error) {
                        $issues[] = ['code' => $error->getMessage(), 'subscriber_id' => $id];
                    }
                    continue;
                }
                $prior[] = $id;
                // Prior accounts still have period movements to verify. Do
                // not infer their opening or closing from a current balance.
                try {
                    $ids = $hashes = [];
                    $net = 0;
                    foreach ($legs as [$event, $entry]) {
                        if (new DateTimeImmutable($event->recorded_at_utc, $utc) >= $end
                            || $entry->created_at < $start || $entry->created_at >= $until) {
                            throw new DomainException('subscriber_cutoff_recorded_outside_period');
                        }
                        $ids[] = (int) $entry->id;
                        $net = SignedMinor::add($net, SignedMinor::parse($event->amount_minor));
                        $hashes[] = [$event->operation_uuid, $event->source_hash];
                    }
                    $observed = DB::table('transactions')->where('subscriber_id', $id)
                        ->where('created_at', '>=', $start)->where('created_at', '<', $until)
                        ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($value) => (int) $value)->all();
                    sort($ids, SORT_NUMERIC);
                    if ($ids !== $observed) throw new DomainException('subscriber_cutoff_prior_or_unregistered_effect');
                    $row = ['subscriber_id' => $id, 'opening_minor' => null,
                        'net_movement_minor' => $net, 'closing_minor' => null,
                        'registry_sources' => $hashes, 'opening_verified' => false];
                    $saved = $predecessor['rows']['subscriber:'.$id] ?? null;
                    $row['predecessor_journal_hash'] = $predecessor['predecessor_hash'];
                    $row['journal_opening_minor'] = $saved['closing'] ?? null;
                    $row['journal_based_closing_minor'] = $saved === null ? null : SignedMinor::add($saved['closing'], $net);
                    $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                    $priorMovements[] = $row;
                } catch (DomainException $error) {
                    $issues[] = ['code' => $error->getMessage(), 'subscriber_id' => $id];
                }
                continue;
            }
            try {
                $rows[] = $this->reconcileAccount($account, $legs, $start, $until);
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'subscriber_id' => $id];
            }
        }
        foreach (array_keys($byAccount) as $id) $issues[] = ['code' => 'subscriber_cutoff_owner_not_present', 'subscriber_id' => $id];
        $base['allocation_policy_verified'] = $issues === [] && $prior === [];
        return $base + ['scanned_cutoff_accounts_match' => $issues === [], 'rows' => $rows,
            'issues' => $issues, 'prior_accounts' => $prior, 'prior_account_movements' => $priorMovements,
            'all_cutoff_accounts_match' => $issues === [] && $prior === []];
    }

    /** A prospective account may be replayed only from a covered birth period.
     * Each adjacent cutoff is verified independently, including its allocation
     * revision, rather than deriving opening by subtraction from today's balance. */
    private function reconcileChain(object $account, \Illuminate\Support\Collection $chain,
        int $periodId, array $currentLegs, array &$coverage, array $predecessor): array
    {
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $id = (int) $account->id;
        $birth = (new DateTimeImmutable($chain[0]->start_on, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $cursor = $chain[0]->start_on;
        $legs = [];
        $previous = null;
        foreach ($chain as $p) {
            if ($p->start_on !== $cursor) throw new DomainException('subscriber_cutoff_period_chain_gap');
            $cursor = $p->next_on;
            $pid = (int) $p->id;
            $coverage[$pid] ??= $this->sources->inspect($pid)['scanned_sources_match'];
            if (! $coverage[$pid]) throw new DomainException('subscriber_cutoff_prior_sources_unverified');
            $from = (new DateTimeImmutable($p->start_on, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = (new DateTimeImmutable($p->next_on, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
            if ($pid === $periodId) {
                $new = $currentLegs;
            } else {
                $new = [];
                $events = DB::table('financial_posting_events as e')
                    ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
                    ->join('transactions as t', 't.id', '=', 'e.source_id')
                    ->where('o.period_id', $pid)->where('e.source_type', 'ledger_transaction')
                    ->where('e.leg', 'subscriber')->where('t.subscriber_id', $id)
                    ->select('e.*', 'o.effective_on', 'o.recorded_at_utc')
                    ->orderBy('e.id')->lockForUpdate()->get();
                foreach ($events as $event) {
                    $new[] = [$event, DB::table('transactions')->where('id', $event->source_id)->lockForUpdate()->sole()];
                }
            }
            $net = 0;
            foreach ($new as [$event, $entry]) {
                if (new DateTimeImmutable($event->recorded_at_utc, $utc) >= new DateTimeImmutable($until, $utc)
                    || $entry->created_at < $from || $entry->created_at >= $until
                    || $entry->created_at < $account->created_at) {
                    throw new DomainException('subscriber_cutoff_recorded_outside_period');
                }
                $net = SignedMinor::add($net, SignedMinor::parse($event->amount_minor));
            }
            $legs = array_merge($legs, $new);
            $row = $this->reconcileAccount($account, $legs, $birth, $until);
            if ($previous !== null && SignedMinor::add($previous['recorded_balance_minor'], $net) !== $row['recorded_balance_minor']) {
                throw new DomainException('subscriber_cutoff_chain_conservation');
            }
            if ($previous !== null) {
                $priorJournal = $pid === $periodId ? $predecessor
                    : app(FinancialOpeningContinuityDiagnostic::class)->predecessor($pid);
                $saved = $priorJournal['rows']['subscriber:'.$id] ?? null;
                if ($saved !== null && $saved['closing'] !== $previous['recorded_balance_minor']) {
                    throw new DomainException('subscriber_cutoff_predecessor_mismatch');
                }
                $row['opening_minor'] = $previous['recorded_balance_minor'];
                $row['net_movement_minor'] = $net;
                $row['closing_minor'] = $row['recorded_balance_minor'];
                $row['prior_source_hash'] = $previous['source_hash'];
                $row['prior_revision_hash'] = $previous['revision_hash'];
                $row['prospective_chain_verified'] = true;
                $row['predecessor_journal_hash'] = $priorJournal['predecessor_hash'];
                $row['predecessor_verified'] = false;
                unset($row['source_hash']);
                $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            }
            if ($pid === $periodId) {
                if ($previous === null) throw new DomainException('subscriber_cutoff_period_chain_gap');
                return $row;
            }
            $previous = $row;
        }
        throw new DomainException('subscriber_cutoff_period_chain_gap');
    }

    private function reconcileAccount(object $account, array $legs, string $start, string $until): array
    {
        $id = (int) $account->id;
        $utc = new DateTimeZone('UTC');
        $end = new DateTimeImmutable($until, $utc);
        if ($account->deleted_at !== null && $account->deleted_at < $until) {
            throw new DomainException('subscriber_cutoff_deleted_account');
        }
        $invoices = $credits = $hashes = $ids = [];
        foreach ($legs as [$event, $entry]) {
            if (new DateTimeImmutable($event->recorded_at_utc, $utc) >= $end
                || $entry->created_at < $start || $entry->created_at >= $until || $entry->created_at < $account->created_at) {
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
            ->where('created_at', '<', $account->created_at)->lockForUpdate()->first()) {
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
        $policyRows = $this->replay($id, $invoices, $credits, $until);
        if ($revision && ! hash_equals($revision->allocation_hash,
            $this->integrity->fingerprint($id, $available, $paid, $unallocated, $policyRows))) {
            throw new DomainException('subscriber_cutoff_allocation_policy_mismatch');
        }
        $row = ['subscriber_id' => $id, 'billed_minor' => $billed, 'available_minor' => $available,
            'allocated_minor' => $paid, 'unallocated_minor' => $unallocated,
            'recorded_balance_minor' => SignedMinor::subtract($available, $billed),
            'revision_id' => $revision ? (int) $revision->id : null,
            'revision_hash' => $revision?->allocation_hash, 'allocation_policy_matched' => true, 'registry_sources' => $hashes];
        $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        return $row;

    }

    /** Amounts come from registered cutoff effects, never today's status or paid amount.
     * Historical corrections are outside this prospective replay; do not guess their targets.
     */
    private function replay(int $subscriberId, array $caps, array $credits, string $until): array
    {
        $ordered = DB::table('invoices')->where('subscriber_id', $subscriberId)
            ->whereIn('id', array_keys($caps))->orderBy('bill_date')->orderBy('id')
            ->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($ordered) !== count($caps)) throw new DomainException('subscriber_cutoff_policy_invoice_missing');
        $sources = [];
        $targets = array_keys($caps);
        foreach ($credits as $key => $minor) {
            if ($minor === 0) continue;
            [$kind, $rawId] = explode(':', $key, 2);
            $id = (int) $rawId;
            $target = null;
            switch ($kind) {
                case 'payment':
                    $doc = \App\Models\Payment::withTrashed()->where('subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($id);
                    $date = $doc->payment_date->format('Y-m-d H:i:s');
                    $target = $doc->invoice_id === null ? null : (int) $doc->invoice_id;
                    if ($target !== null) $targets[] = $target;
                    $order = 0;
                    break;
                case 'transfer':
                    $doc = \App\Models\PaymentAccountTransfer::where('to_subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($id);
                    $date = $doc->created_at->format('Y-m-d H:i:s');
                    $order = 1;
                    break;
                case 'held':
                    $doc = \App\Models\TreasuryHeldCashCredit::where('subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($id);
                    $date = $doc->effective_on.' 00:00:00';
                    $order = 2;
                    break;
                default:
                    throw new DomainException('subscriber_cutoff_policy_source_unsupported');
            }
            $sources[] = ['id' => $id, 'kind' => $order, 'date' => $date, 'target' => $target, 'minor' => $minor];
        }
        if ($targets !== [] && \App\Models\HistoricalInvoiceCorrection::query()->where('created_at', '<', $until)
            ->where(fn ($q) => $q->whereIn('original_invoice_id', $targets)->orWhereIn('replacement_invoice_id', $targets))
            ->lockForUpdate()->first()) {
            throw new DomainException('subscriber_cutoff_historical_target_requires_proof');
        }
        usort($sources, fn ($a, $b) => [$a['date'], $a['kind'], $a['id']] <=> [$b['date'], $b['kind'], $b['id']]);
        $rows = [];
        foreach ($sources as $credit) {
            $remaining = $credit['minor'];
            $source = ['payment_id' => $credit['kind'] === 0 ? $credit['id'] : null,
                'account_transfer_id' => $credit['kind'] === 1 ? $credit['id'] : null];
            if ($credit['kind'] === 2) $source['held_cash_credit_id'] = $credit['id'];
            $target = $credit['target'];
            $queue = $ordered;
            if ($target !== null && isset($caps[$target])) {
                $queue = array_merge([$target], array_values(array_filter($ordered, fn ($id) => $id !== $target)));
            }
            foreach ($queue as $invoiceId) {
                if ($remaining === 0) break;
                $minor = min($caps[$invoiceId], $remaining);
                if ($minor === 0) continue;
                $caps[$invoiceId] = SignedMinor::subtract($caps[$invoiceId], $minor);
                $remaining = SignedMinor::subtract($remaining, $minor);
                $rows[] = $source + ['invoice_id' => $invoiceId, 'amount_minor' => $minor];
            }
            if ($remaining > 0) $rows[] = $source + ['invoice_id' => null, 'amount_minor' => $remaining];
        }
        return $rows;
    }
}
