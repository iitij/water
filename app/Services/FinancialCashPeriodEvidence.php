<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Actual cutoff cash evidence, never a complete close or historical adoption. */
final class FinancialCashPeriodEvidence
{
    public function __construct(private TreasuryCashCountService $counts,
        private FinancialPeriodMovementDiagnostic $movements) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('cash_period_requires_gate');
        DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $last = (new DateTimeImmutable($period->next_on, new DateTimeZone('Asia/Aden')))->modify('-1 day')->format('Y-m-d');
        $ended = now('Asia/Aden')->toDateString() >= $period->next_on;
        $base = ['period_ended' => $ended, 'closure_authorized' => false];
        if (! $ended) return $base + ['all_accounts_matched' => false, 'rows' => [], 'issues' => []];
        $accounts = DB::table('treasury_accounts')->where('channel', 'cash')->where('opening_on', '<=', $last)
            ->orderBy('id')->lockForUpdate()->get();
        $issues = $rows = [];
        $movement = $this->movements->inspect($periodId);
        if (! $movement['recorded_movements_verified']) {
            $issues[] = ['code' => 'cash_period_registered_movements_unverified'];
        }
        $byAccount = [];
        foreach ($movement['rows'] as $row) {
            if ($row['sector'] === 'cash') $byAccount[$row['key']] = $row;
        }
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            try {
                $observation = $this->counts->periodEndObservation($id, $period->start_on, $period->next_on);
                if (! $movement['recorded_movements_verified']) continue;
                $recorded = $byAccount[(string) $id] ?? null;
                $birth = $account->opening_on >= $period->start_on;
                $openingDocument = $recorded['recorded_opening_document_minor'] ?? 0;
                $net = $recorded['net_movement_minor'] ?? 0;
                // A prior account requires separate opening continuity. Do not
                // infer it by subtracting movements from observed closing cash.
                if ($birth && ($recorded === null || $openingDocument !== SignedMinor::parse($account->opening_minor)
                    || SignedMinor::add($openingDocument, $net) !== $observation['closing_minor'])) {
                    throw new DomainException('cash_period_new_account_conservation');
                }
                if (! $birth && $openingDocument !== 0) throw new DomainException('cash_period_opening_date_mismatch');
                $row = $observation + ['opening_minor' => $birth ? 0 : null,
                    'registered_opening_document_minor' => $openingDocument, 'registered_movement_minor' => $net,
                    'movement_minor' => SignedMinor::add($openingDocument, $net),
                    'movement_hash' => $movement['movement_hash'], 'predecessor_verified' => false];
                $row['source_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                $rows[] = $row;
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'account_id' => $id];
            }
        }
        return $base + ['all_accounts_matched' => $issues === [],
            'rows' => $issues === [] ? $rows : [], 'issues' => $issues];
    }
}
