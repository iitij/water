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

        $utcBoundary = static fn (string $date) => (new DateTimeImmutable($date, new DateTimeZone('Asia/Aden')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $startUtc = $utcBoundary($startOn);
        $endUtc = $utcBoundary($nextOn);
        // An approval can still be present in the audit after a session row changes.
        $physicalAudits = AuditEvent::query()->where('event', 'cash_session.approved')
            ->where('entity_type', CashSession::class)->where('occurred_at', '<', $endUtc)
            ->where('after->settlement_basis', 'physical_cash');
        $sessions = CashSession::query()->where(function ($query) use ($endUtc, $nextOn, $physicalAudits) {
            $query->where(function ($q) use ($endUtc) {
                $q->where('state', CashSession::STATE_APPROVED)->where('settlement_basis', 'physical_cash')
                    ->where(fn ($q) => $q->where('approved_at', '<', $endUtc)->orWhereNull('approved_at'));
            })->orWhereIn('id', (clone $physicalAudits)->select('entity_id'))->orWhereIn('id', CashHandover::query()->select('cash_session_id')->where('received_on', '<', $nextOn));
        })->orderBy('id')->lockForUpdate()->get();

        $rows = [];
        $issues = [];
        $missingIds = $physicalAudits->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cash_sessions')
            ->whereColumn('cash_sessions.id', 'audit_events.entity_id'))->orderBy('id')->lockForUpdate()->pluck('entity_id');
        foreach ($missingIds as $missing) {
            $issues[] = ['session_id' => $missing, 'code' => 'approved_custody_session_missing'];
        }
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
            'unapproved_at_opening' => $this->unapprovedAt($startUtc, $startOn),
            'unapproved_at_closing' => $this->unapprovedAt($endUtc, $nextOn),
            'matched' => $issues === [], 'issues' => $issues, 'totals' => $issues === [] ? $totals : null,
            'rows' => $issues === [] ? $rows : []];
    }

    /** Reconstruct approval status at the cutoff even after a later decision. */
    private function unapprovedAt(string $cutoffUtc, string $localDate): array
    {
        $sessions = DB::table('cash_sessions')
            ->where(fn ($q) => $q->where('opened_at', '<', $cutoffUtc)
                ->orWhere(fn ($q) => $q->whereNull('opened_at')->where('session_date', '<', $localDate)))
            ->where(fn ($q) => $q->whereNull('approved_at')->orWhere('approved_at', '>=', $cutoffUtc)
                ->orWhere('state', '!=', CashSession::STATE_APPROVED)
                ->orWhereNull('settlement_basis')->orWhere('settlement_basis', '!=', 'physical_cash'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'user_id', 'opened_at', 'closed_at']);
        $items = [];
        foreach ($sessions as $session) {
            $items[] = ['session_id' => (string) $session->id, 'collector_id' => (string) $session->user_id,
                'closed_at_cutoff' => $session->closed_at !== null && $session->closed_at < $cutoffUtc];
        }

        return $items;
    }

    private function sessionRow(CashSession $session, string $startOn, string $nextOn): array
    {
        $driver = $session->getConnection()->getDriverName();
        $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
        $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
        $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
        $utc = new DateTimeZone('UTC');
        $closed = $session->getRawOriginal('closed_at');
        $approvedAt = $session->getRawOriginal('approved_at');
        if (! $session->isApproved() || $session->settlement_basis !== 'physical_cash'
            || $closed === null || $approvedAt === null || $closed > $approvedAt
            || (int) $session->approved_by <= 0 || (int) $session->approved_by === (int) $session->user_id
            || ! Cash::consistent($counted, $expected, $variance)) {
            throw new DomainException('approved_custody_count_unverified');
        }

        $approvedInstant = new DateTimeImmutable($approvedAt, $utc);
        $closedInstant = new DateTimeImmutable($closed, $utc);
        $audits = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
            ->where('entity_id', (string) $session->id)->orderBy('id')->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || (int) $audit->actor_user_id !== (int) $session->approved_by
            || ($audit->after['id'] ?? null) !== (int) $session->id || ($audit->after['state'] ?? null) !== 'approved'
            || ! $this->sameInstant($audit->after['approved_at'] ?? null, $approvedInstant)
            || ! $this->sameInstant($audit->after['closed_at'] ?? null, $closedInstant)
            || ($audit->after['counted_amount'] ?? null) !== SignedMinor::decimal($counted)
            || ($audit->after['expected_amount'] ?? null) !== SignedMinor::decimal($expected)
            || ($audit->after['variance_amount'] ?? null) !== SignedMinor::decimal($variance)) {
            throw new DomainException('approved_custody_approval_unverified');
        }

        $approvedOn = $approvedInstant->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        if ($approvedOn >= $nextOn) {
            throw new DomainException('approved_custody_source_outside_period');
        }
        $opening = $approvedOn < $startOn ? $counted : 0;
        $approved = $approvedOn >= $startOn ? $counted : 0;
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

    private function sameInstant(mixed $snapshot, DateTimeImmutable $instant): bool
    {
        if (! is_string($snapshot)) return false;
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $snapshot);
        return $parsed !== false && $parsed->format(DATE_ATOM) === $snapshot
            && $parsed->getTimestamp() === $instant->getTimestamp();
    }
}
