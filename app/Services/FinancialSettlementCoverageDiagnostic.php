<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only settlement evidence diagnostic. Not a close proof source.
 * Bank coverage is through an explicit date inside the selected period;
 * unresolved items cannot be netted away. No history is adopted or repaired.
 */
final class FinancialSettlementCoverageDiagnostic
{
    public function __construct(
        private FinancialPostingRegistry $registry,
        private BankReconciliationService $bank,
        private GatedCountedCashWriter $counted,
        private TreasuryRegisterService $treasury,
    ) {}

    public function inspect(User $actor, int $periodId, string $through): array
    {
        if (DB::transactionLevel() === 0) throw new DomainException('settlement_coverage_requires_gate');
        $this->registry->verifyPeriod('water', $periodId);
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->first();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $through, new DateTimeZone('Asia/Aden'));
        if (! $period || $date === false || $date->format('Y-m-d') !== $through
            || $through < $period->start_on || $through >= $period->next_on
            || $through > now('Asia/Aden')->toDateString()) {
            throw new DomainException('settlement_coverage_date');
        }
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('settlement_coverage_issuance_lock');
        }
        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
        $this->treasury->authorize($actor);
        $start = $period->start_on;
        $next = static fn (string $day): string => (new DateTimeImmutable($day, new DateTimeZone('Asia/Aden')))->modify('+1 day')->format('Y-m-d');
        $end = $next($through);
        // Effective DATE fields are local business dates; decisions and
        // audit timestamps are UTC instants. Use the same half-open cutoff
        // as the posting-source diagnostic.
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $fromUtc = (new DateTimeImmutable($start, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = (new DateTimeImmutable($end, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $issues = [];
        $issue = static function (string $code, string $source, string $id, array $details = []) use (&$issues): void {
            $issues[$code.'|'.$source.'|'.$id] = ['code' => $code, 'source' => $source, 'id' => $id] + $details;
        };
        $accounts = DB::table('treasury_accounts')->where('channel', 'bank')->where('opening_on', '<=', $through)
            ->orderBy('id')->lockForUpdate()->get();
        $statementsChecked = 0;
        $bankCandidates = [];
        $periodEnded = $through === (new DateTimeImmutable($period->next_on, $zone))->modify('-1 day')->format('Y-m-d')
            && now('Asia/Aden')->toDateString() >= $period->next_on;
        foreach ($accounts as $account) {
            $cursor = max($start, $account->opening_on);
            $issueCount = count($issues);
            $first = null;
            $last = null;
            $statementProofs = [];
            $statements = DB::table('bank_statements')->where('treasury_account_id', $account->id)
                ->where('from_date', '<=', $through)->where('to_date', '>=', $cursor)
                ->orderBy('from_date')->orderBy('id')->lockForUpdate()->get();
            $prior = null;
            foreach ($statements as $statement) {
                $id = (string) $statement->id;
                $statementsChecked++;
                if ($statement->from_date > $cursor) {
                    $issue('bank_statement_gap', 'treasury_accounts', $account->id.':'.$cursor,
                        ['missing_from' => $cursor, 'missing_until_exclusive' => $statement->from_date]);
                }
                if ($prior !== null && $statement->from_date <= $prior->to_date) {
                    $issue('bank_statement_overlap', 'bank_statements', $id);
                }
                if ($statement->from_date < max($start, $account->opening_on) || $statement->to_date > $through) {
                    $issue('bank_statement_boundary_requires_proof', 'bank_statements', $id);
                }
                $report = $this->bank->inspect($actor, (int) $statement->id);
                $proofs = AuditEvent::query()->where('event', 'bank_statement.imported')
                    ->where('entity_type', 'bank_statement')->where('entity_id', $id)->lockForUpdate()->get();
                if ($proofs->count() !== 1 || (int) $proofs[0]->actor_user_id !== (int) $statement->imported_by
                    || ($proofs[0]->after['content_hash'] ?? null) !== $statement->content_hash) {
                    $issue('bank_statement_import_proof_missing_or_conflicting', 'bank_statements', $id);
                }
                if (! $report['fully_matched']) {
                    $issue('bank_statement_unresolved', 'bank_statements', $id, [
                        'unmatched_bank_count' => count($report['unmatched_bank_lines']),
                        'unmatched_ledger_count' => count($report['unmatched_ledger_postings']),
                        'bank_unmatched_minor' => $report['bank_unmatched_minor'],
                        'ledger_unmatched_minor' => $report['ledger_unmatched_minor'],
                        'opening_difference_minor' => $report['opening_difference_minor'],
                        'closing_difference_minor' => $report['closing_difference_minor'],
                        'integrity_matches' => $report['statement_integrity_matches'],
                    ]);
                }
                $first ??= $statement;
                $last = $statement;
                $statementProofs[] = ['id' => (int) $statement->id, 'content_hash' => $statement->content_hash,
                    'import_audit_id' => $proofs->count() === 1 ? (int) $proofs[0]->id : null];
                $cursor = max($cursor, $next($statement->to_date));
                $prior = $statement;
            }
            if ($cursor < $end) {
                $issue('bank_statement_gap', 'treasury_accounts', $account->id.':'.$cursor,
                    ['missing_from' => $cursor, 'missing_until_exclusive' => $end]);
            }
            if ($periodEnded && count($issues) === $issueCount && $first !== null && $last !== null) {
                $bankCandidates[] = ['account' => $account, 'first' => $first, 'last' => $last, 'statements' => $statementProofs];
            }
        }

        $bankEvidence = ['period_ended' => $periodEnded, 'all_accounts_matched' => false,
            'rows' => [], 'issues' => [], 'closure_authorized' => false];
        if ($periodEnded) {
            $bankEvidence = $this->bankPeriodEvidence($period, $accounts->count(), $bankCandidates);
        }

        // Audit decisions in the inspected interval, plus effective dates in it.
        // Verify the complete supporting session, including prior allocations,
        // without inventing or modifying any historical posting.
        $reviews = DB::table('late_receipt_reviews')->where('status', 'approved')->where('settlement_method', 'already_counted')
            ->where(function ($q) use ($start, $end, $fromUtc, $toUtc) {
                $q->where(fn ($q) => $q->where('payment_date', '>=', $start)->where('payment_date', '<', $end))
                    ->orWhere(fn ($q) => $q->where('decided_at', '>=', $fromUtc)->where('decided_at', '<', $toUtc));
            })->orderBy('id')->lockForUpdate()->get();
        $sessions = $reviews->pluck('cash_session_id')->unique()->sort()->values();
        $verifiedSessions = 0;
        foreach ($sessions as $sessionId) {
            try {
                $this->counted->verifySession((int) $sessionId);
                $verifiedSessions++;
            } catch (DomainException $error) {
                $issue('counted_cash_session_unverified', 'cash_sessions', (string) $sessionId, ['reason' => $error->getMessage()]);
            }
        }
        foreach (AuditEvent::query()->where('event', 'financial.counted_cash_allocated')
            ->where('occurred_at', '>=', $fromUtc)->where('occurred_at', '<', $toUtc)->orderBy('id')->lockForUpdate()->get() as $audit) {
            $review = DB::table('late_receipt_reviews')->where('id', $audit->entity_id)->lockForUpdate()->first();
            if ($audit->entity_type !== 'late_receipt_review' || ! $review || $review->status !== 'approved'
                || $review->settlement_method !== 'already_counted') {
                $issue('counted_cash_orphan_audit', 'audit_events', (string) $audit->id);
            }
        }
        ksort($issues, SORT_STRING);
        return ['period_id' => $periodId, 'start_on' => $start, 'through' => $through,
            'bank_accounts_checked' => $accounts->count(), 'bank_statements_checked' => $statementsChecked,
            'bank_period_evidence' => $bankEvidence,
            'counted_reviews_checked' => $reviews->count(), 'counted_sessions_verified' => $verifiedSessions,
            'issues' => array_values($issues), 'scanned_settlements_match' => $issues === [],
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    /** Called only with statement candidates derived above under the scope lock.
     * The statement opening is independent documentary evidence for the
     * inspected interval, not an adopted historical balance or predecessor.
     */
    private function bankPeriodEvidence(object $period, int $accountCount, array $candidates): array
    {
        $issues = [];
        $rows = [];
        if (count($candidates) !== $accountCount) {
            $issues[] = ['code' => 'bank_statement_period_coverage_incomplete'];
        }
        if ($candidates !== []) {
            // Validate canonical operation bytes and the actual supported
            // registry source hashes before using any registered amounts.
            app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', (int) $period->id);
        }
        foreach ($candidates as $candidate) {
            $account = $candidate['account'];
            $id = (int) $account->id;
            $opening = \App\Support\SignedMinor::parse($candidate['first']->opening_minor);
            $closing = \App\Support\SignedMinor::parse($candidate['last']->closing_minor);
            $movements = 0;
            $openingDocument = 0;
            $proofs = [];
            $valid = true;
            $registered = static function (string $type, string $source, string $leg, string $date, int $minor) use ($period): ?object {
                $rows = DB::table('financial_posting_events as e')
                    ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
                    ->where('e.scope_key', 'water')->where('e.source_type', $type)
                    ->where('e.source_id', $source)->where('e.leg', $leg)
                    ->select('e.*', 'o.period_id', 'o.effective_on')->lockForUpdate()->get();
                if ($rows->count() !== 1) return null;
                $event = $rows->first();
                return (int) $event->period_id === (int) $period->id && $event->effective_on === $date
                    && $event->currency === 'YER' && \App\Support\SignedMinor::parse($event->amount_minor) === $minor
                    ? $event : null;
            };
            $openedInPeriod = $account->opening_on >= $period->start_on;
            if ($openedInPeriod) {
                $openingDocument = \App\Support\SignedMinor::parse($account->opening_minor);
                $event = $registered('treasury_opening', (string) $id, 'opening', $account->opening_on, $openingDocument);
                if ($event === null || $opening !== $openingDocument) {
                    $valid = false;
                } else {
                    $proofs[] = [$event->operation_uuid, $event->source_type, $event->source_id, $event->source_hash];
                }
            }
            $postings = DB::table('treasury_postings')->where('treasury_account_id', $id)
                ->where('effective_on', '>=', max($period->start_on, $account->opening_on))
                ->where('effective_on', '<', $period->next_on)->orderBy('effective_on')->orderBy('id')->lockForUpdate()->get();
            foreach ($postings as $posting) {
                $minor = \App\Support\SignedMinor::parse($posting->amount_minor);
                // Only actual supported bank sources can participate. In
                // particular, no cash-only held payout alias is accepted.
                $event = in_array($posting->kind, ['internal_transfer_out', 'internal_transfer_in', 'bank_fee', 'bank_collection'], true)
                    ? $registered('treasury_posting', (string) $posting->id, 'treasury', $posting->effective_on, $minor) : null;
                if ($event === null) {
                    $valid = false;
                    continue;
                }
                $movements = \App\Support\SignedMinor::add($movements, $minor);
                $proofs[] = [$event->operation_uuid, $event->source_type, $event->source_id, $event->source_hash];
            }
            if (! $valid || \App\Support\SignedMinor::add($opening, $movements) !== $closing) {
                $issues[] = ['code' => 'bank_period_registered_movements_unmatched', 'account_id' => $id];
                continue;
            }
            $periodOpening = $openedInPeriod ? 0 : $opening;
            $periodMovement = \App\Support\SignedMinor::add($openingDocument, $movements);
            $row = ['account_id' => $id, 'opening_minor' => $periodOpening,
                'registered_opening_document_minor' => $openingDocument,
                'registered_movement_minor' => $movements, 'movement_minor' => $periodMovement,
                'closing_minor' => $closing, 'statements' => $candidate['statements'],
                'registry_sources' => $proofs, 'predecessor_verified' => false];
            $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $rows[] = $row;
        }
        return ['period_ended' => true, 'all_accounts_matched' => $issues === [],
            'rows' => $issues === [] ? $rows : [], 'issues' => $issues, 'closure_authorized' => false];
    }
}
