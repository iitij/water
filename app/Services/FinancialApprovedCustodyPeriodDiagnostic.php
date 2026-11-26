<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashHandover;
use App\Models\CashSession;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

/** Outstanding approved physical counts. Open tills and unapproved counts are outside this measure. */
final class FinancialApprovedCustodyPeriodDiagnostic
{
    public function __construct(private GatedCashHandoverWriter $handovers) {}

    public function inspect(string $startOn, string $nextOn): array
    {
        if (DB::transactionLevel() !== 1) {
            throw new DomainException('approved_custody_requires_locked_transaction');
        }

        $endUtc = (new DateTimeImmutable($nextOn, new DateTimeZone('Asia/Aden')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sessions = CashSession::query()->where(function ($query) use ($endUtc, $nextOn) {
            $query->where(function ($q) use ($endUtc) {
                $q->where('state', CashSession::STATE_APPROVED)->where('settlement_basis', 'physical_cash')
                    ->where(fn ($q) => $q->where('approved_at', '<', $endUtc)->orWhereNull('approved_at'));
            })->orWhereIn('id', CashHandover::query()->select('cash_session_id')->where('received_on', '<', $nextOn));
        })->orderBy('id')->lockForUpdate()->get();

        $rows = [];
        $issues = [];
        $totals = ['opening_minor' => 0, 'approved_minor' => 0, 'handed_over_minor' => 0, 'closing_minor' => 0];
        foreach ($sessions as $session) {
            try {
                $row = $this->sessionRow($session, $startOn, $nextOn);
                foreach (array_keys($totals) as $key) {
                    $totals[$key] = SignedMinor::add($totals[$key], $row[$key]);
                }
                if ($row['opening_minor'] || $row['approved_minor'] || $row['handed_over_minor'] || $row['approved_on'] >= $startOn) {
                    $rows[] = $row;
                }
            } catch (DomainException $error) {
                $issues[] = ['session_id' => (string) $session->id, 'code' => $error->getMessage()];
            } catch (InvalidArgumentException|OverflowException) {
                $issues[] = ['session_id' => (string) $session->id, 'code' => 'approved_custody_amount_invalid'];
            }
        }

        return ['basis' => 'approved_physical_counts_less_registered_handovers',
            'matched' => $issues === [], 'issues' => $issues, 'totals' => $issues === [] ? $totals : null,
            'rows' => $issues === [] ? $rows : []];
    }

    private function sessionRow(CashSession $session, string $startOn, string $nextOn): array
    {
        $driver = $session->getConnection()->getDriverName();
        $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
        $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
        $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
        if (! $session->isApproved() || $session->settlement_basis !== 'physical_cash'
            || $session->closed_at === null || $session->approved_at === null || $session->closed_at > $session->approved_at
            || (int) $session->approved_by <= 0 || (int) $session->approved_by === (int) $session->user_id
            || ! Cash::consistent($counted, $expected, $variance)) {
            throw new DomainException('approved_custody_count_unverified');
        }

        $audits = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
            ->where('entity_id', (string) $session->id)->orderBy('id')->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || (int) $audit->actor_user_id !== (int) $session->approved_by
            || ($audit->after['id'] ?? null) !== (int) $session->id || ($audit->after['state'] ?? null) !== 'approved'
            || ($audit->after['approved_at'] ?? null) !== $session->approved_at->toIso8601String()
            || ($audit->after['closed_at'] ?? null) !== $session->closed_at->toIso8601String()
            || ($audit->after['counted_amount'] ?? null) !== SignedMinor::decimal($counted)
            || ($audit->after['expected_amount'] ?? null) !== SignedMinor::decimal($expected)
            || ($audit->after['variance_amount'] ?? null) !== SignedMinor::decimal($variance)) {
            throw new DomainException('approved_custody_approval_unverified');
        }

        $approvedOn = $session->approved_at->copy()->setTimezone('Asia/Aden')->toDateString();
        $opening = $approvedOn < $startOn ? $counted : 0;
        $approved = $approvedOn >= $startOn && $approvedOn < $nextOn ? $counted : 0;
        $handedOver = 0;
        $receipts = [];
        $handovers = CashHandover::query()->where('cash_session_id', $session->id)->where('received_on', '<', $nextOn)
            ->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
        foreach ($handovers as $handover) {
            $receipt = $this->handovers->verifiedReceipt($handover);
            if ($receipt['received_on'] < $approvedOn) {
                throw new DomainException('approved_custody_handover_precedes_approval');
            }
            if ($receipt['received_on'] < $startOn) {
                $opening = SignedMinor::subtract($opening, $receipt['amount_minor']);
            } else {
                $handedOver = SignedMinor::add($handedOver, $receipt['amount_minor']);
            }
            $receipts[] = $receipt;
        }
        $closing = SignedMinor::subtract(SignedMinor::add($opening, $approved), $handedOver);
        if ($opening < 0 || $closing < 0) {
            throw new DomainException('approved_custody_overdrawn');
        }

        return ['session_id' => (string) $session->id, 'collector_id' => (string) $session->user_id,
            'approval_audit_id' => (string) $audit->id, 'approved_on' => $approvedOn, 'receipts' => $receipts,
            'opening_minor' => $opening, 'approved_minor' => $approved,
            'handed_over_minor' => $handedOver, 'closing_minor' => $closing];
    }
}
