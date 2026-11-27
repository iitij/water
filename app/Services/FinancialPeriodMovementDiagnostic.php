<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Verified recorded movements only; not a complete trial balance or close proof.
 * Never derive openings by subtracting movements from mutable current balances.
 */
final class FinancialPeriodMovementDiagnostic
{
    public function __construct(private FinancialSourceCoverageDiagnostic $sources) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() <= 0 || $periodId <= 0) {
            throw new DomainException('period_movements_requires_gate');
        }
        // Re-derive both directions of source membership; do not accept caller
        // supplied flags or a previously cached source report.
        $source = $this->sources->inspect($periodId);
        $base = ['period_id' => $periodId, 'start_on' => $source['start_on'], 'next_on' => $source['next_on'],
            'source_issues' => $source['issues'], 'opening_verified' => false,
            'period_closing_verified' => false, 'closure_authorized' => false];
        if (! $source['scanned_sources_match'] || $source['unverified_registry_legs'] !== 0
            || $source['expected_legs'] !== $source['matched_legs']
            || $source['matched_legs'] !== $source['registry_events']) {
            return $base + ['recorded_movements_verified' => false, 'rows' => [], 'sector_totals' => [],
                'movement_hash' => null, 'registry_events' => $source['registry_events']];
        }
        $rows = $totals = [];
        $physicalEffects = [];
        $count = 0;
        $digest = hash_init('sha256');
        $encode = static fn (array $value): string => json_encode($value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        hash_update($digest, $encode(['format' => 1, 'period_id' => $periodId,
            'start_on' => $base['start_on'], 'next_on' => $base['next_on']])."\n");
        $events = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('o.period_id', $periodId)->select('e.*', 'o.effective_on', 'o.recorded_at_utc')
            ->orderBy('e.operation_uuid')->orderBy('e.source_type')->orderBy('e.source_id')->orderBy('e.leg')
            ->lockForUpdate()->get();
        $end = new DateTimeImmutable($base['next_on'], new DateTimeZone('Asia/Aden'));
        foreach ($events as $event) {
            if (new DateTimeImmutable($event->recorded_at_utc, new DateTimeZone('UTC')) >= $end) {
                $base['source_issues'][] = ['code' => 'period_movement_recorded_after_cutoff',
                    'operation_uuid' => $event->operation_uuid];
                return $base + ['recorded_movements_verified' => false, 'rows' => [], 'sector_totals' => [],
                    'movement_hash' => null, 'registry_events' => $source['registry_events']];
            }
        }
        foreach ($events as $event) {
            [$sector, $owner, $opening] = $this->destination($event);
            $minor = SignedMinor::parse($event->amount_minor);
            $key = $sector.':'.$owner;
            $rows[$key] ??= ['sector' => $sector, 'key' => $owner, 'recorded_opening_document_minor' => 0,
                'incoming_minor' => 0, 'outgoing_minor' => 0, 'net_movement_minor' => 0,
                'events' => 0, 'opening_minor' => null, 'closing_minor' => null];
            $row = &$rows[$key];
            $row['events']++;
            if ($opening) {
                $row['recorded_opening_document_minor'] = SignedMinor::add($row['recorded_opening_document_minor'], $minor);
            } else {
                $field = $minor >= 0 ? 'incoming_minor' : 'outgoing_minor';
                $row[$field] = SignedMinor::add($row[$field], $minor >= 0 ? $minor : SignedMinor::negate($minor));
                $row['net_movement_minor'] = SignedMinor::add($row['net_movement_minor'], $minor);
            }
            unset($row);
            // Secondary source view only: never add these effects to sector totals.
            if ($event->source_type === 'treasury_posting' && $event->leg === 'treasury') {
                $posting = DB::table('treasury_postings')->where('id', $event->source_id)->lockForUpdate()->sole();
                if (in_array($posting->kind, ['internal_transfer_out', 'internal_transfer_in', 'cash_handover'], true)) {
                    $physicalEffects[] = ['kind' => $posting->kind, 'posting_id' => (int) $posting->id,
                        'account_id' => (int) $posting->treasury_account_id, 'amount_minor' => $minor,
                        'source_hash' => $event->source_hash];
                }
            }

            hash_update($digest, $encode([$event->operation_uuid, $event->source_type, $event->source_id,
                $event->leg, $event->effective_on, $sector, $owner, $opening, (string) $minor, $event->source_hash])."\n");
            $count++;
        }
        if ($count !== $source['registry_events']) throw new DomainException('period_movement_event_count');
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            $sector = $row['sector'];
            $totals[$sector] ??= ['recorded_opening_document_minor' => 0, 'incoming_minor' => 0,
                'outgoing_minor' => 0, 'net_movement_minor' => 0, 'events' => 0];
            foreach (array_keys($totals[$sector]) as $field) {
                $totals[$sector][$field] = SignedMinor::add($totals[$sector][$field], $row[$field]);
            }
        }
        ksort($totals, SORT_STRING);
        // Liability legs below are recorded reclassification/discharge effects,
        // not a total liability roll-forward (recognition is separate evidence).
        return $base + ['recorded_movements_verified' => true, 'registry_events' => $count,
            'rows' => array_values($rows), 'sector_totals' => $totals,
            'physical_effects' => $physicalEffects,
            'movement_hash' => hash_final($digest), 'liability_rollforward_verified' => false];
    }

    private function destination(object $event): array
    {
        $type = $event->source_type;
        $leg = $event->leg;
        if ($type === 'ledger_transaction' && $leg === 'subscriber') {
            $entry = DB::table('transactions')->where('id', $event->source_id)->lockForUpdate()->first();
            if (! $entry || $entry->deleted_at !== null) throw new DomainException('period_movement_owner_missing');
            $id = $this->id($entry->subscriber_id);
            if (! DB::table('subscribers')->where('id', $id)->lockForUpdate()->first()) {
                throw new DomainException('period_movement_owner_missing');
            }
            return ['subscriber', (string) $id, false];
        }
        if ($type === 'treasury_opening' && $leg === 'opening') {
            return $this->treasury($this->id($event->source_id), true);
        }
        if (($type === 'treasury_posting' || $type === 'held_cash_payout') && $leg === 'treasury') {
            $posting = DB::table('treasury_postings')
                ->where($type === 'treasury_posting' ? 'id' : 'held_cash_payout_uuid', $event->source_id)
                ->lockForUpdate()->sole();
            return $this->treasury($this->id($posting->treasury_account_id), false);
        }
        if ($type === 'held_cash_assignment' && in_array($leg, ['identified_liability', 'unidentified_liability'], true)) {
            $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $event->source_id)->lockForUpdate()->sole();
        } elseif ($leg === 'identified_liability' && in_array($type, ['held_cash_payout', 'held_cash_credit'], true)) {
            $document = $type === 'held_cash_payout'
                ? DB::table('treasury_held_cash_payouts')->where('operation_uuid', $event->source_id)->lockForUpdate()->sole()
                : DB::table('treasury_held_cash_credits')->where('id', $event->source_id)->lockForUpdate()->sole();
            $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $document->assignment_uuid)->lockForUpdate()->sole();
        } else {
            throw new DomainException('period_movement_source_unsupported');
        }
        return ['held_liability_effect', $leg === 'unidentified_liability'
            ? 'lot-'.$this->id($assignment->liability_id) : 'assignment-'.$assignment->operation_uuid, false];
    }

    private function treasury(int $id, bool $opening): array
    {
        $account = DB::table('treasury_accounts')->where('id', $id)->lockForUpdate()->first();
        if (! $account || ! in_array($account->channel, ['cash', 'bank'], true)) {
            throw new DomainException('period_movement_owner_missing');
        }
        return [$account->channel, (string) $id, $opening];
    }

    private function id(mixed $raw): int
    {
        $id = SignedMinor::parseUnsigned($raw);
        if ($id === 0) throw new DomainException('period_movement_owner_missing');
        return $id;
    }
}
