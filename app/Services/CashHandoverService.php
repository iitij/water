<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\User;
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

    private function minor($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function eligible(CashSession $session): bool
    {
        return $session->isApproved() && $session->closed_at !== null
            && $session->settlement_basis === 'physical_cash'
            && $session->counted_amount !== null && $session->expected_amount !== null
            && $session->variance_amount !== null
            && $this->minor($session->counted_amount) >= 0 && $this->minor($session->expected_amount) >= 0
            && $this->minor($session->counted_amount) - $this->minor($session->expected_amount)
                === $this->minor($session->variance_amount);
    }

    private function receivedMinor(CashSession $session, bool $locked = false): int
    {
        $query = CashHandover::where('cash_session_id', $session->id);
        if ($locked) $query->lockForUpdate();
        return $query->get(['amount'])->sum(fn ($row) => $this->minor($row->amount));
    }

    public function summary(CashSession $session): array
    {
        $received = $this->receivedMinor($session);
        $target = $this->eligible($session) ? $this->minor($session->counted_amount) : null;
        $status = match (true) {
            $target === null => $received === 0 ? 'not_eligible' : 'inconsistent',
            $received < 0 || $received > $target => 'inconsistent',
            $target === 0 => 'not_required',
            $received === $target => 'complete',
            $received === 0 => 'pending',
            default => 'partial',
        };
        $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        return [
            'status' => $status,
            'basis' => 'approved_counted_cash_custody',
            'target_amount' => $target === null ? null : $money($target),
            'received_amount' => $money($received),
            'remaining_amount' => $target === null || $status === 'inconsistent' ? null : $money($target - $received),
        ];
    }

    public function record(CashSession $session, User $receiver, float $amount, string $reference, string $date, string $evidence): CashHandover
    {
        abort_unless($this->canRecord($receiver), 403);
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        if (! is_finite($amount) || $amount <= 0 || $amount > 999999999999.99 || round($amount, 2) != $amount
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()) {
            throw ValidationException::withMessages(['handover' => 'بيانات التسليم أو المبلغ أو التاريخ غير صالحة.']);
        }
        return DB::transaction(function () use ($session, $receiver, $amount, $reference, $date, $evidence): CashHandover {
            // Global financial issuance lock serializes reference checks across tills.
            // No subscriber locks are acquired by this custody-only operation.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_if((int) $session->user_id === (int) $receiver->id, 403, 'لا يجوز للمحصّل توثيق الاستلام من نفسه.');
            $requested = $this->minor($amount);
            $existing = CashHandover::where('reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->cash_session_id === (int) $session->id
                    && (int) $existing->received_by === (int) $receiver->id
                    && $this->minor($existing->amount) === $requested
                    && $existing->received_on->toDateString() === $date
                    && $existing->evidence === $evidence, 409, 'مرجع التسليم مستخدم لعملية أخرى.');
                return $existing;
            }
            if (! $this->eligible($session) || $date < $session->closed_at->toDateString()) {
                throw ValidationException::withMessages(['handover' => 'يلزم صندوق نقدي معتمد وتاريخ تسليم لا يسبق الإغلاق.']);
            }
            $before = $this->receivedMinor($session, true);
            $target = $this->minor($session->counted_amount);
            if ($before < 0 || $requested > $target - $before) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز النقد المعدود المتبقي للتسليم.']);
            }
            $handover = CashHandover::create([
                'cash_session_id' => $session->id,
                'received_by' => $receiver->id,
                'amount' => $amount,
                'reference' => $reference,
                'received_on' => $date,
                'evidence' => $evidence,
            ]);
            app(AuditLogger::class)->recordRequired('cash_handover.received', [
                'entity_type' => CashHandover::class,
                'entity_id' => $handover->id,
                'actor_user_id' => $receiver->id,
                'before' => ['received_minor' => $before],
                'after' => $handover->toArray() + ['received_minor' => $before + $requested,
                    'target_minor' => $target, 'subscriber_receipt_created' => false],
            ]);
            return $handover;
        }, 3);
    }
}
