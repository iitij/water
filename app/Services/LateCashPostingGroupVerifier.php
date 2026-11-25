<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LateCashPostingGroup;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive grouped evidence verifier. Never certifies completeness or closes
 * a period. Scope-wide scan intentionally detects missing non-anchor parts.
 * The legacy format 1 verifier and its digest remain unchanged and blocked
 * on grouped data pending integration of both evidence streams.
 */
final class LateCashPostingGroupVerifier
{
    public function __construct(
        private LateCashPostingGroupEvidence $evidence,
        private LateCashPostingGroup $group,
    ) {}

    public function verifyPeriod(string $scope, int $periodId): array
    {
        $this->need(DB::transactionLevel() > 0 && $scope === 'water' && $periodId > 0,
            'late_cash_group_verifier_requires_gate');
        $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
        $this->need($gate !== null && $gate->mode === 'ACTIVE' && $gate->currency === 'YER'
            && $gate->timezone === 'Asia/Aden' && (string) $gate->calendar_version === '1',
            'late_cash_group_verifier_scope');
        // Acquire period locks before business-source locks, in a stable order.
        $periods = DB::table('financial_periods')->where('scope_key', $scope)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $period = $periods->get($periodId);
        $this->need($period !== null && in_array($period->state, ['OPEN', 'FROZEN', 'CLOSED'], true),
            'late_cash_group_verifier_period');
        $expected = app(\App\Support\FinancialClosePolicy::class)->open($scope, $period->month, $gate->timezone);
        $this->need($period->start_on === $expected['start'] && $period->next_on === $expected['next'],
            'late_cash_group_verifier_period');
        $this->need(! DB::table('financial_posting_group_parts as p')
            ->join('financial_posting_operations as op', 'op.operation_uuid', '=', 'p.operation_uuid')
            ->where('op.scope_key', $scope)->where('op.payload_format', '<>', 2)->lockForUpdate()->first(),
            'late_cash_group_verifier_format');

        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'late_cash_group_issuance_lock');
        $digest = hash_init('sha256');
        hash_update($digest, $this->encode(['format' => 2, 'scope' => $scope,
            'period_id' => $periodId, 'month' => $period->month])."\n");
        $groups = 0;
        $effects = 0;
        $previous = null;
        $query = DB::table('financial_posting_operations')->where('scope_key', $scope)
            ->where('payload_format', '<>', 1)->lockForUpdate();
        foreach ($query->lazyById(250, 'operation_uuid') as $header) {
            $uuid = $header->operation_uuid;
            $this->need((string) $header->payload_format === '2' && is_string($uuid)
                && ($previous === null || strcmp($previous, $uuid) < 0), 'late_cash_group_verifier_format');
            $previous = $uuid;
            $parts = DB::table('financial_posting_group_parts')->where('operation_uuid', $uuid)
                ->orderBy('id')->lockForUpdate()->get();
            $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->need($parts->count() === 2 && $events->count() === 2, 'late_cash_group_verifier_parts');

            $contexts = [];
            $byRole = [];
            $seenEvents = [];
            foreach ($parts as $part) {
                $role = $part->role;
                $this->need(in_array($role, ['subscriber', 'treasury'], true)
                    && ! isset($byRole[$role]) && ! isset($seenEvents[$part->event_id]),
                    'late_cash_group_verifier_parts');
                $event = $events->get($part->event_id);
                $this->need($event !== null && $event->scope_key === $scope
                    && $event->operation_uuid === $uuid && $event->leg === $role,
                    'late_cash_group_verifier_event');
                $context = ['period_id' => $this->counter($part->period_id), 'scope' => $scope,
                    'effective_on' => $part->effective_on, 'epoch' => $this->counter($part->fence_epoch),
                    'version' => $this->counter($part->period_version)];
                $this->need($periods->has($context['period_id']) && is_string($part->effective_on)
                    && ! isset($contexts[$part->effective_on]), 'late_cash_group_verifier_parts');
                $contexts[$part->effective_on] = $context;
                $byRole[$role] = ['context' => $context, 'event' => $event];
                $seenEvents[$part->event_id] = true;
            }
            // Locate the original source through the unique review UUID, not
            // the canonical payload or a caller-supplied review identifier.
            $reviews = DB::table('late_receipt_reviews')->where('sync_uuid', $uuid)->lockForUpdate()->get();
            $this->need($reviews->count() === 1
                && ! DB::table('noncash_collection_claims')->where('sync_uuid', $uuid)->lockForUpdate()->first(),
                'late_cash_group_verifier_origin');
            $proof = $this->evidence->deriveHistorical($this->counter($reviews->first()->id), $contexts);
            $this->group->assertMatches($proof, $header->canonical_payload, $header->payload_hash);
            $anchor = $proof['payload']['parts'][0]['context'];
            $this->need($header->scope_key === $scope && $proof['payload']['operation_uuid'] === $uuid
                && $this->counter($header->period_id) === $anchor['period_id']
                && $header->effective_on === $anchor['effective_on']
                && $this->counter($header->fence_epoch) === $anchor['epoch']
                && $this->counter($header->period_version) === $anchor['version'],
                'late_cash_group_verifier_anchor');
            $recorded = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',
                $header->recorded_at_utc, new DateTimeZone('UTC'));
            $this->need($recorded !== false && $recorded->format('Y-m-d\TH:i:s.u\Z') === $header->recorded_at_utc,
                'late_cash_group_verifier_recorded_at');
            $localDay = $recorded->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d');
            $localParts = [];
            foreach ($proof['payload']['parts'] as $actual) {
                $stored = $byRole[$actual['role']];
                $this->need($actual['context'] === $stored['context']
                    && $actual['context']['effective_on'] <= $localDay, 'late_cash_group_verifier_parts');
                foreach ($actual['leg'] as $key => $value) {
                    $this->need((string) $stored['event']->$key === (string) $value, 'late_cash_group_verifier_event');
                }
                if ($actual['context']['period_id'] === $periodId) {
                    $localParts[] = $actual;
                }
            }
            if ($localParts !== []) {
                // Bind the complete group hash, but count only effects that
                // belong to the requested period (two if both dates share it).
                hash_update($digest, $this->encode([$uuid, $proof['payload_hash'],
                    $header->recorded_at_utc, $localParts])."\n");
                $groups++;
                $effects += count($localParts);
            }
        }
        return ['group_operations' => $groups, 'group_events' => $effects,
            'group_registry_hash' => hash_final($digest), 'source_coverage_verified' => false,
            'closure_authorized' => false];
    }

    private function counter(mixed $value): int
    {
        $text = (string) $value;
        $this->need((is_int($value) || is_string($value))
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $text) === 1
            && (string) (int) $text === $text, 'late_cash_group_verifier_counter');
        return (int) $text;
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
