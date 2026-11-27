<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Reconcile recorded claim evidence at a half-open cutoff, not current status.
 * Pending claims are not receipts, cash, recognized receivables or liabilities.
 * Posting coverage and authorization are independent checks.
 */
final class FinancialCollectionPeriodEvidence
{
    private function instant(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') throw new DomainException('collection_evidence_timestamp_invalid');
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function equal(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null ? $a === $b
            : is_scalar($a) && is_scalar($b) && (string) $a === (string) $b;
    }

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() !== 1) throw new DomainException('collection_cutoff_requires_locked_transaction');
        DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $cutoff = (new DateTimeImmutable($period->next_on, new DateTimeZone('Asia/Aden')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $base = ['period_id' => $periodId, 'cutoff_utc' => $cutoff, 'period_ended' => now('Asia/Aden')->toDateString() >= $period->next_on,
            'recorded_evidence_matched' => false, 'rows' => [], 'issues' => [], 'pending_count' => null, 'closure_authorized' => false];
        if (! $base['period_ended']) return $base;
        $rows = $issues = [];
        $pending = 0;
        foreach ([
            'noncash_collection_claims' => ['noncash_claim', 'noncash_collection_claim', 'submitted_by', 'cleared',
                ['amount_minor', 'instrument_reference', 'instrument_on'], ['decision_evidence']],
            'late_receipt_reviews' => ['late_receipt', 'late_receipt_review', 'collector_id', 'approved',
                ['cash_session_id', 'submitted_meter_id', 'amount', 'payment_date', 'reference_number', 'reason'],
                ['settlement_method', 'evidence_reference', 'bank_clearance_reference', 'evidence']],
        ] as $table => [$prefix, $type, $owner, $approved, $sourceFields, $decisionFields]) {
            $events = [$prefix.'.submitted', $prefix.'.rejected', $prefix.'.'.$approved];
            $audits = AuditEvent::query()->whereIn('event', $events)->where('occurred_at', '<', $cutoff)
                ->orderBy('id')->lockForUpdate()->get();
            $grouped = $audits->groupBy('entity_id');
            $documents = DB::table($table)->where(function ($q) use ($cutoff) {
                $q->where('created_at', '<', $cutoff)->orWhereNull('created_at')->orWhere('decided_at', '<', $cutoff);
            })->orderBy('id')->lockForUpdate()->get();
            $seen = [];
            foreach ($documents as $doc) {
                $id = (string) $doc->id;
                $seen[$id] = true;
                try {
                    $created = $this->instant($doc->created_at);
                    if ($created >= $cutoff) throw new DomainException('collection_submission_after_cutoff');
                    $proofs = $grouped->get($id, collect());
                    $submissions = $proofs->where('event', $events[0])->values();
                    if ($submissions->count() !== 1) throw new DomainException('collection_submission_audit_missing_or_duplicate');
                    $submission = $submissions->sole();
                    $submittedAt = $this->instant($submission->getRawOriginal('occurred_at'));
                    if ($submission->entity_type !== $type || (int) $submission->actor_user_id !== (int) $doc->$owner
                        || $submittedAt < $created || ! is_array($submission->after)) {
                        throw new DomainException('collection_submission_audit_conflict');
                    }
                    $source = [];
                    foreach (array_merge(['sync_uuid', 'subscriber_id', 'invoice_id', 'payment_method', 'notes', $owner], $sourceFields) as $field) {
                        $after = $submission->after;
                        if (! array_key_exists($field, $after)) throw new DomainException('collection_submission_payload_missing');
                        if ($field === 'amount') {
                            $minor = Cash::positiveStored($doc->$field, DB::getDriverName(), DecimalDomain::D12);
                            if ($minor !== MoneyBoundary::numericInput($after[$field], DecimalDomain::D12, false, 1)) {
                                throw new DomainException('collection_submission_payload_conflict');
                            }
                            $source['amount_minor'] = $minor;
                        } else {
                            if (! $this->equal($doc->$field, $after[$field])) throw new DomainException('collection_submission_payload_conflict');
                            $source[$field] = $doc->$field === null ? null : (string) $doc->$field;
                        }
                    }
                    $decisions = $proofs->whereIn('event', [$events[1], $events[2]])->values();
                    $decided = $doc->decided_at === null ? null : $this->instant($doc->decided_at);
                    $status = 'pending';
                    $decision = null;
                    if ($decided !== null && $decided < $cutoff) {
                        if ($decisions->count() !== 1 || ! in_array($doc->status, ['rejected', $approved], true)
                            || $doc->decided_by === null || (int) $doc->decided_by === (int) $doc->$owner
                            || $decided < $created || ($doc->status === $approved) !== ($doc->payment_id !== null)) {
                            throw new DomainException('collection_decision_missing_or_conflicting');
                        }
                        $audit = $decisions->sole();
                        $auditAt = $this->instant($audit->getRawOriginal('occurred_at'));
                        $after = $audit->after;
                        if ($audit->entity_type !== $type || $audit->event !== $prefix.'.'.$doc->status
                            || (int) $audit->actor_user_id !== (int) $doc->decided_by || $auditAt < $decided
                            || ! is_array($after) || $this->instant($after['decided_at'] ?? null) !== $decided) {
                            throw new DomainException('collection_decision_audit_conflict');
                        }
                        $decision = ['audit_id' => (int) $audit->id, 'decided_at_utc' => $decided];
                        foreach (array_merge(['status', 'decided_by', 'payment_id'], $decisionFields) as $field) {
                            // Some rejection audits omit the deliberately null payment/bank reference.
                            if (! $this->equal($doc->$field, $after[$field] ?? null)) throw new DomainException('collection_decision_payload_conflict');
                            $decision[$field] = $doc->$field === null ? null : (string) $doc->$field;
                        }
                        $status = $doc->status;
                    } else {
                        if ($decisions->isNotEmpty() || ($decided === null && ($doc->status !== 'pending'
                            || $doc->decided_by !== null || $doc->payment_id !== null))) {
                            throw new DomainException('collection_decision_cutoff_conflict');
                        }
                        $pending++;
                    }
                    $rows[] = ['source_type' => $type, 'id' => (int) $doc->id, 'created_at_utc' => $created,
                        'submission_audit_id' => (int) $submission->id, 'source' => $source,
                        'status_at_cutoff' => $status, 'decision' => $decision];
                } catch (\Exception $error) {
                    $issues[] = ['source_type' => $type, 'id' => $id,
                        'code' => $error instanceof DomainException ? $error->getMessage() : 'collection_evidence_unreadable'];
                }
            }
            foreach ($grouped as $id => $proofs) {
                if (! isset($seen[(string) $id])) $issues[] = ['source_type' => $type, 'id' => (string) $id, 'code' => 'collection_orphan_audit'];
            }
        }
        return array_replace($base, ['recorded_evidence_matched' => $issues === [], 'rows' => $issues === [] ? $rows : [],
            'issues' => $issues, 'pending_count' => $issues === [] ? $pending : null]);
    }
}
