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
 * - السندات الملغاة (`voided_at`) لا تُحسب في المتوقع أبداً.
 * - الفرق = المسلَّم فعلاً − المتوقع، ويُحفظ نصاً في قاعدة البيانات بلا تقريب.
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
        $existing = $this->openSessionFor($user);
        if ($existing) {
            throw ValidationException::withMessages([
                'cash_session' => ['لديك صندوق مفتوح بالفعل. أغلقه قبل فتح صندوق جديد.'],
            ]);
        }

        $session = DB::transaction(function () use ($user, $openingAmount, $squareId, $notes) {
            $session = CashSession::create([
                'user_id' => $user->id,
                'square_id' => $squareId,
                'session_date' => now()->toDateString(),
                'opening_amount' => round($openingAmount, 2),
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

    /** إجمالي السندات الصالحة (غير الملغاة) في الصندوق. */
    public function expectedAmount(CashSession $session): float
    {
        $total = Payment::query()
            ->where('cash_session_id', $session->id)
            ->whereNull('voided_at')
            ->sum('amount');

        return round((float) $total, 2);
    }

    /** إغلاق الصندوق وتسجيل النقد المسلَّم والفرق. */
    public function close(CashSession $session, float $countedAmount, ?string $notes = null): CashSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'state' => ['لا يمكن إغلاق صندوق غير مفتوح.'],
            ]);
        }

        return DB::transaction(function () use ($session, $countedAmount, $notes) {
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            $expected = $this->expectedAmount($session);
            $counted = round($countedAmount, 2);

            $before = $this->summary($session);
            $session->fill([
                'expected_amount' => $expected,
                'counted_amount' => $counted,
                'variance_amount' => round($counted - $expected, 2),
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
        if ($session->state === CashSession::STATE_OPEN) {
            throw ValidationException::withMessages([
                'state' => ['أغلق الصندوق قبل اعتماده.'],
            ]);
        }

        return DB::transaction(function () use ($session, $approve, $approver, $notes) {
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

        $payments = Payment::query()
            ->where('cash_session_id', $session->id)
            ->whereNull('voided_at');

        $byMethod = (clone $payments)
            ->selectRaw('payment_method, COUNT(*) as receipts, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'receipts' => (int) $row->receipts,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])->values()->all();

        $voided = Payment::query()
            ->where('cash_session_id', $session->id)
            ->whereNotNull('voided_at')
            ->count();

        return [
            'id' => $session->id,
            'state' => $session->state,
            'session_date' => optional($session->session_date)->format('Y-m-d'),
            'collector' => $session->user?->name,
            'square' => $session->square?->name,
            'opening_amount' => number_format((float) $session->opening_amount, 2, '.', ''),
            'expected_amount' => number_format((float) ($session->expected_amount ?? $this->expectedAmount($session)), 2, '.', ''),
            'counted_amount' => $session->counted_amount === null
                ? null
                : number_format((float) $session->counted_amount, 2, '.', ''),
            'variance_amount' => $session->variance_amount === null
                ? null
                : number_format((float) $session->variance_amount, 2, '.', ''),
            'receipts' => (clone $payments)->count(),
            'voided_receipts' => $voided,
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
