<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دورة حياة صندوق المحصّل: فتح ← تحصيل ← إغلاق وتسوية ← اعتماد.
 *
 * القواعد المالية:
 * - كل سند يُربط بالصندوق المفتوح للمستخدم نفسه (وإلا يبقى بلا صندوق، كما في
 *   السندات القديمة).
 * - النقد المتوقع = رصيد الافتتاح + السندات النقدية السارية فقط.
 * - التحويلات والشيكات تُعرض مستقلة ولا تُعد نقداً في الصندوق.
 * - الفرق = النقد المعدود − النقد المتوقع.
 *
 * مرجع: خطة الإصدار 2.0 — الفصل 5.2.
 */
class CashSessionService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** الصندوق المفتوح الحالي للمستخدم، إن وُجد. */
    public function openSessionFor(User $user): ?CashSession
    {
        return CashSession::query()
            ->where('user_id', $user->id)
            ->where('state', CashSession::STATE_OPEN)
            ->latest('id')
            ->first();
    }

    /**
     * فتح صندوق جديد. يمنع وجود صندوقين مفتوحين لنفس المستخدم لأن ذلك يجعل
     * التسوية اليومية غامضة.
     */
    public function open(
        User $user,
        float $openingAmount = 0,
        ?int $squareId = null,
        ?string $notes = null,
    ): CashSession {
        $this->assertMoney($openingAmount, 'opening_amount');
        $session = DB::transaction(function () use ($user, $openingAmount, $squareId, $notes) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            if ($this->openSessionFor($user)) {
                throw ValidationException::withMessages([
                    'cash_session' => ['لديك صندوق مفتوح بالفعل. أغلقه قبل فتح صندوق جديد.'],
                ]);
            }
            $session = CashSession::create([
                'user_id' => $user->id,
                'square_id' => $squareId,
                'session_date' => now()->toDateString(),
                'opening_amount' => $openingAmount,
                'settlement_basis' => 'physical_cash',
                'state' => CashSession::STATE_OPEN,
                'opened_at' => now(),
                'opening_notes' => $notes,
            ]);

            $this->audit->record('cash_session.opened', [
                'entity_type' => CashSession::class,
                'entity_id' => $session->id,
                'after' => $this->summary($session->fresh()),
            ]);

            return $session;
        });

        return $session->fresh();
    }

    private function assertMoney(float $amount, string $field): void
    {
        if (! is_finite($amount) || $amount < 0 || $amount > 999999999999.99 || round($amount, 2) != $amount) {
            throw ValidationException::withMessages([$field => 'المبلغ غير سالب وبحد أقصى منزلتين عشريتين وضمن النطاق المسموح.']);
        }
    }

    /** Physical cash only; finalized sessions retain their recorded snapshot. */
    public function expectedAmount(CashSession $session): float
    {
        if (! $session->isOpen() && $session->expected_amount !== null) {
            return (float) $session->expected_amount;
        }
        $cash = Payment::query()
            ->where('cash_session_id', $session->id)
            ->where('payment_method', 'cash')
            ->whereNull('voided_at')
            ->sum('amount');

        $expectedMinor = (int) round((float) $session->opening_amount * 100)
            + (int) round((float) $cash * 100);
        $expected = $expectedMinor / 100;
        $this->assertMoney($expected, 'expected_amount');

        return $expected;
    }

    /** إغلاق الصندوق وتسجيل النقد المسلَّم والفرق. */
    public function close(CashSession $session, float $countedAmount, ?string $notes = null): CashSession
    {
        $this->assertMoney($countedAmount, 'counted_amount');
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'state' => ['لا يمكن إغلاق صندوق غير مفتوح.'],
            ]);
        }

        return DB::transaction(function () use ($session, $countedAmount, $notes) {
            User::query()->lockForUpdate()->findOrFail($session->user_id);
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['state' => ['لا يمكن إغلاق صندوق غير مفتوح.']]);
            }
            $expected = $this->expectedAmount($session);
            $counted = $countedAmount;

            $before = $this->summary($session);
            $session->fill([
                'settlement_basis' => 'physical_cash',
                'expected_amount' => $expected,
                'counted_amount' => $counted,
                'variance_amount' => ((int) round($counted * 100) - (int) round($expected * 100)) / 100,
                'state' => CashSession::STATE_CLOSED,
                'closed_at' => now(),
                'closing_notes' => $notes,
            ])->save();

            $this->audit->record('cash_session.closed', [
                'entity_type' => CashSession::class,
                'entity_id' => $session->id,
                'before' => $before,
                'after' => $this->summary($session),
            ]);

            return $session->fresh();
        });
    }

    /** اعتماد مشرف أو رفض مع ملاحظة. */
    public function decide(
        CashSession $session,
        bool $approve,
        User $approver,
        ?string $notes = null,
    ): CashSession {
        $notes = $notes === null ? null : trim($notes);
        if (! $approve && mb_strlen($notes ?? '') < 10) {
            throw ValidationException::withMessages([
                'notes' => ['اكتب سبب الرفض بوضوح (10 أحرف على الأقل).'],
            ]);
        }
        if (mb_strlen($notes ?? '') > 1000) {
            throw ValidationException::withMessages([
                'notes' => ['ملاحظات القرار لا تتجاوز 1000 حرف.'],
            ]);
        }

        return DB::transaction(function () use ($session, $approve, $approver, $notes) {
            // Read and check the state under the same row lock as the decision.
            // A stale web form or a retried mobile request must not reverse a
            // decision already made by another supervisor.
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->isOpen()) {
                throw ValidationException::withMessages([
                    'state' => ['أغلق الصندوق قبل اعتماده.'],
                ]);
            }
            abort_unless($session->isClosed(), 409, 'اتُّخذ قرار لهذا الصندوق بالفعل. حدّث القائمة.');

            $before = $this->summary($session);
            $session->fill([
                'state' => $approve ? CashSession::STATE_APPROVED : CashSession::STATE_REJECTED,
                'approved_at' => $approve ? now() : null,
                'approved_by' => $approver->id,
                'approval_notes' => $notes,
            ])->save();

            $this->audit->record(
                $approve ? 'cash_session.approved' : 'cash_session.rejected',
                [
                    'entity_type' => CashSession::class,
                    'entity_id' => $session->id,
                    'before' => $before,
                    'after' => $this->summary($session),
                    'actor_user_id' => $approver->id,
                ],
            );

            return $session->fresh();
        });
    }

    /** ملخص جاهز للعرض في التطبيق وتقرير الإغلاق. */
    public function summary(?CashSession $session): array
    {
        if (! $session) {
            return [];
        }

        // Corrections posted after approval do not rewrite the original till report.
        $payments = Payment::query()->where('cash_session_id', $session->id)
            ->where(function ($query) {
                $query->whereNull('voided_at')->orWhere('voided_via', 'closed_correction');
            });

        $byMethod = (clone $payments)
            ->selectRaw('payment_method, COUNT(*) as receipts, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'receipts' => (int) $row->receipts,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])->values()->all();

        $cashMinor = (int) round((float) (collect($byMethod)->firstWhere('payment_method', 'cash')['total'] ?? 0) * 100);
        $totalMinor = collect($byMethod)->sum(fn ($row) => (int) round((float) $row['total'] * 100));

        $voided = Payment::query()
            ->where('cash_session_id', $session->id)
            ->whereNotNull('voided_at')
            ->where(function ($query) { $query->whereNull('voided_via')->orWhere('voided_via', '!=', 'closed_correction'); })
            ->count();
        $corrections = DB::table('closed_cash_corrections')->where('cash_session_id', $session->id)->orderBy('id')->get();

        $allocation = app(LateReceiptReviewService::class)->countedCashAllocation($session);
        $handover = app(CashHandoverService::class)->summary($session);

        return [
            'id' => $session->id,
            'state' => $session->state,
            'session_date' => optional($session->session_date)->format('Y-m-d'),
            'collector' => $session->user?->name,
            'square' => $session->square?->name,
            'opening_amount' => number_format((float) $session->opening_amount, 2, '.', ''),
            'settlement_basis' => $session->isOpen() ? 'physical_cash' : ($session->settlement_basis ?? 'legacy_recorded'),
            'expected_amount' => number_format($this->expectedAmount($session), 2, '.', ''),
            'cash_collections' => number_format($cashMinor / 100, 2, '.', ''),
            'non_cash_collections' => number_format(($totalMinor - $cashMinor) / 100, 2, '.', ''),
            'total_collections' => number_format($totalMinor / 100, 2, '.', ''),
            'counted_amount' => $session->counted_amount === null
                ? null
                : number_format((float) $session->counted_amount, 2, '.', ''),
            'variance_amount' => $session->variance_amount === null
                ? null
                : number_format((float) $session->variance_amount, 2, '.', ''),
            'counted_cash_allocation_eligible' => $allocation['eligible'],
            'counted_cash_surplus_capacity' => number_format($allocation['capacity_minor'] / 100, 2, '.', ''),
            'counted_cash_surplus_allocated' => number_format($allocation['allocated_minor'] / 100, 2, '.', ''),
            'counted_cash_surplus_available' => number_format($allocation['available_minor'] / 100, 2, '.', ''),
            'treasury_handover' => $handover,
            'receipts' => (clone $payments)->count(),
            'voided_receipts' => $voided,
            'post_close_corrections' => $corrections,
            'post_close_correction_total' => number_format((float) $corrections->sum('amount'), 2, '.', ''),
            'by_method' => $byMethod,
            'opened_at' => optional($session->opened_at)->toIso8601String(),
            'closed_at' => optional($session->closed_at)->toIso8601String(),
            'approved_at' => optional($session->approved_at)->toIso8601String(),
            'approved_by' => $session->approver?->name,
            'notes' => $session->opening_notes,
            'closing_notes' => $session->closing_notes,
            'approval_notes' => $session->approval_notes,
        ];
    }
}
