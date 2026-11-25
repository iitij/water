<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LateCashPostingGroup;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive format 2 persistence candidate. Both source effects must already
 * exist inside the caller's gate-first transaction. Caller must let any
 * failure abort that outer transaction; the inner savepoint protects the
 * registry itself. No historical adoption, route or closure activation.
 */
final class LateCashPostingGroupRegistry
{
    public function __construct(
        private LateCashPostingGroupEvidence $evidence,
        private LateCashPostingGroup $group,
    ) {}

    public function record(int $reviewId, array $contexts, DateTimeImmutable $recordedAt): bool
    {
        $this->need(DB::transactionLevel() > 0, 'late_cash_group_requires_gate');
        return DB::transaction(function () use ($reviewId, $contexts, $recordedAt): bool {
            $proof = $this->evidence->derive($reviewId, $contexts);
            $payload = $proof['payload'];
            $uuid = $payload['operation_uuid'];
            $parts = $payload['parts'];
            $anchor = $parts[0]['context'];
            $today = $recordedAt->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
            $this->need($parts[1]['context']['effective_on'] <= $today, 'late_cash_group_future_date');
            // A review is the only reservation allowed for this group.
            $this->need(! DB::table('noncash_collection_claims')->where('sync_uuid', $uuid)->lockForUpdate()->first(),
                'late_cash_group_uuid_conflict');
            $existing = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->need((string) $existing->payload_format === '2' && $existing->scope_key === 'water'
                    && (string) $existing->period_id === (string) $anchor['period_id']
                    && $existing->effective_on === $anchor['effective_on']
                    && (string) $existing->fence_epoch === (string) $anchor['epoch']
                    && (string) $existing->period_version === (string) $anchor['version'], 'late_cash_group_replay_conflict');
                $this->group->assertMatches($proof, $existing->canonical_payload, $existing->payload_hash);
                $this->assertParts($uuid, $parts);
                return false;
            }

            foreach ($parts as $part) {
                $leg = $part['leg'];
                $this->need(! DB::table('financial_posting_events')->where('scope_key', 'water')
                    ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
                    ->where('leg', $leg['leg'])->lockForUpdate()->first(), 'late_cash_group_effect_already_registered');
            }
            // The old header's period/date is only an anchor for format 2.
            // Each part carries the authoritative posting context.
            DB::table('financial_posting_operations')->insert([
                'operation_uuid' => $uuid, 'payload_format' => 2, 'scope_key' => 'water',
                'period_id' => $anchor['period_id'], 'effective_on' => $anchor['effective_on'],
                'fence_epoch' => $anchor['epoch'], 'period_version' => $anchor['version'],
                'recorded_at_utc' => $recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'payload_hash' => $proof['payload_hash'], 'canonical_payload' => $proof['canonical_payload'],
            ]);
            foreach ($parts as $part) {
                $context = $part['context'];
                $eventId = DB::table('financial_posting_events')->insertGetId($part['leg'] + [
                    'operation_uuid' => $uuid, 'scope_key' => 'water',
                ]);
                DB::table('financial_posting_group_parts')->insert([
                    'operation_uuid' => $uuid, 'role' => $part['role'], 'event_id' => $eventId,
                    'period_id' => $context['period_id'], 'effective_on' => $context['effective_on'],
                    'fence_epoch' => $context['epoch'], 'period_version' => $context['version'],
                ]);
            }
            $this->assertParts($uuid, $parts);
            return true;
        }, 1);
    }

    private function assertParts(string $uuid, array $parts): void
    {
        $stored = DB::table('financial_posting_group_parts')->where('operation_uuid', $uuid)
            ->orderBy('id')->lockForUpdate()->get();
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->need($stored->count() === 2 && $events->count() === 2, 'late_cash_group_parts_corrupt');
        foreach ($parts as $part) {
            $row = $stored->firstWhere('role', $part['role']);
            $context = $part['context'];
            $this->need($row !== null && (string) $row->period_id === (string) $context['period_id']
                && $row->effective_on === $context['effective_on']
                && (string) $row->fence_epoch === (string) $context['epoch']
                && (string) $row->period_version === (string) $context['version'], 'late_cash_group_parts_corrupt');
            $event = $events->get($row->event_id);
            $this->need($event !== null && $event->operation_uuid === $uuid && $event->scope_key === 'water',
                'late_cash_group_parts_corrupt');
            foreach ($part['leg'] as $key => $value) {
                $this->need((string) $event->$key === (string) $value, 'late_cash_group_parts_corrupt');
            }
        }
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
