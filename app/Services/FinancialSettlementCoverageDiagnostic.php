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
        $bankBalances = [];
        foreach ($accounts as $account) {
            $cursor = max($start, $account->opening_on);
            $statements = DB::table('bank_statements')->where('treasury_account_id', $account->id)
                ->where('from_date', '<=', $through)->where('to_date', '>=', $cursor)
                ->orderBy('from_date')->orderBy('id')->lockForUpdate()->get();
            $prior = null;
            $opening = null;
            $closing = null;
            $statementEvidence = [];
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
                $opening ??= $report['recorded_opening_minor'];
                $closing = $report['recorded_closing_minor'];
                $statementEvidence[] = ['id' => $id, 'content_hash' => $statement->content_hash];
                $cursor = max($cursor, $next($statement->to_date));
                $prior = $statement;
            }
            if ($cursor < $end) {
                $issue('bank_statement_gap', 'treasury_accounts', $account->id.':'.$cursor,
                    ['missing_from' => $cursor, 'missing_until_exclusive' => $end]);
            }
            if ($opening !== null) {
                $bankBalances[] = [
                    'account_id' => (string) $account->id,
                    'account_opening_on' => $account->opening_on,
                    'from_on' => max($start, $account->opening_on),
                    'statement_opening_minor' => $opening,
                    'statement_closing_minor' => $closing,
                    'statements' => $statementEvidence,
                ];
            }
        }
        $bankStatementsMatch = $issues === [];

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
            'bank_statements_match' => $bankStatementsMatch,
            'bank_statement_balances' => $bankStatementsMatch ? $bankBalances : [],
            'counted_reviews_checked' => $reviews->count(), 'counted_sessions_verified' => $verifiedSessions,
            'issues' => array_values($issues), 'scanned_settlements_match' => $issues === [],
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }
}
