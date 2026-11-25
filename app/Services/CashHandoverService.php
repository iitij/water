<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Evidence of cash custody transfer, never a new subscriber receipt. */
final class CashHandoverService
{
    public function canRecord(User $actor): bool
    {
        return $actor->hasRole('super_admin')
            || ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve'));
    }

    private function sessionMinor(CashSession $session, string $field, bool $signed = false): ?int
    {
        return Cash::stored($session->getRawOriginal($field), $session->getConnection()->getDriverName(),
            DecimalDomain::D14, $signed, true);
    }

    private function eligible(CashSession $session): bool
    {
        return $session->isApproved() && $session->closed_at !== null
            && $session->settlement_basis === 'physical_cash'
            && Cash::consistent($this->sessionMinor($session, 'counted_amount'),
                $this->sessionMinor($session, 'expected_amount'), $this->sessionMinor($session, 'variance_amount', true));
    }

    private function receivedMinor(CashSession $session, bool $locked = false): int
    {
        $query = CashHandover::where('cash_session_id', $session->id);
        if ($locked) $query->lockForUpdate();
        $driver = $session->getConnection()->getDriverName();
        return SignedMinor::sum($query->orderBy('id')->get(['id', 'amount'])->map(fn ($row) =>
            Cash::positiveStored($row->getRawOriginal('amount'), $driver, DecimalDomain::D14)));
    }

    public function summary(CashSession $session): array
    {
        $received = $this->receivedMinor($session);
        $target = $this->eligible($session) ? $this->sessionMinor($session, 'counted_amount') : null;
        $status = match (true) {
            $target === null => $received === 0 ? 'not_eligible' : 'inconsistent',
            $received < 0 || $received > $target => 'inconsistent',
            $target === 0 => 'not_required',
            $received === $target => 'complete',
            $received === 0 => 'pending',
            default => 'partial',
        };
        $money = static fn (int $minor): string => SignedMinor::decimal($minor);
        return [
            'status' => $status,
            'basis' => 'approved_counted_cash_custody',
            'target_amount' => $target === null ? null : $money($target),
            'received_amount' => $money($received),
            'remaining_amount' => $target === null || $status === 'inconsistent' ? null : $money(SignedMinor::subtract($target, $received)),
        ];
    }

    public function record(CashSession $session, User $receiver, mixed $amount, string $reference, string $date, string $evidence): CashHandover
    {
        abort_unless($this->canRecord($receiver), 403);
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        $requested = Cash::input($amount, 'handover', DecimalDomain::D14, 1);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()) {
            throw ValidationException::withMessages(['handover' => 'بيانات التسليم أو المبلغ أو التاريخ غير صالحة.']);
        }
        return DB::transaction(function () use ($session, $receiver, $requested, $reference, $date, $evidence): CashHandover {
            // Global financial issuance lock serializes reference checks across tills.
            // No subscriber locks are acquired by this custody-only operation.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_if((int) $session->user_id === (int) $receiver->id, 403, 'لا يجوز للمحصّل توثيق الاستلام من نفسه.');
            $existing = CashHandover::where('reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->cash_session_id === (int) $session->id
                    && (int) $existing->received_by === (int) $receiver->id
                    && Cash::positiveStored($existing->getRawOriginal('amount'), $existing->getConnection()->getDriverName(), DecimalDomain::D14) === $requested
                    && $existing->received_on->toDateString() === $date
                    && $existing->evidence === $evidence, 409, 'مرجع التسليم مستخدم لعملية أخرى.');
                return $existing;
            }
            abort_if(DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists(), 409, 'مرجع التسليم مستخدم في مستند مالي آخر.');
            if (! $this->eligible($session) || $date < $session->closed_at->toDateString()) {
                throw ValidationException::withMessages(['handover' => 'يلزم صندوق نقدي معتمد وتاريخ تسليم لا يسبق الإغلاق.']);
            }
            $before = $this->receivedMinor($session, true);
            $target = $this->sessionMinor($session, 'counted_amount');
            if ($before < 0 || $before > $target || $requested > SignedMinor::subtract($target, $before)) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز النقد المعدود المتبقي للتسليم.']);
            }
            $handover = CashHandover::create([
                'cash_session_id' => $session->id,
                'received_by' => $receiver->id,
                'amount' => Cash::sessionDecimal($requested),
                'reference' => $reference,
                'received_on' => $date,
                'evidence' => $evidence,
            ]);
            if (! $handover->exists || $handover->getKey() === null) {
                throw new \RuntimeException('Cash handover was not persisted.');
            }
            $audit = app(AuditLogger::class)->recordRequired('cash_handover.received', [
                'entity_type' => CashHandover::class,
                'entity_id' => $handover->id,
                'actor_user_id' => $receiver->id,
                'before' => ['received_minor' => $before],
                'after' => $handover->toArray() + ['received_minor' => SignedMinor::add($before, $requested),
                    'target_minor' => $target, 'subscriber_receipt_created' => false],
            ]);
            if (! $audit->exists || $audit->getKey() === null) {
                throw new \RuntimeException('Cash handover requires a persisted audit event.');
            }
            return $handover;
        }, 3);
    }
}
