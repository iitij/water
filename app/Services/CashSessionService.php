<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;
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
        mixed $openingAmount = 0,
        ?int $squareId = null,
        ?string $notes = null,
    ): CashSession {
        $openingMinor = Cash::input($openingAmount, 'opening_amount');
        if ($openingMinor !== 0) {
            throw ValidationException::withMessages(['opening_amount' => 'الافتتاح بمبلغ موجب يتطلب سند تمويل مثبتاً من الخزينة.']);
        }
        return DB::transaction(function () use ($user, $squareId, $notes): CashSession {
            $session = $this->createOpenSession($user, 0, $squareId, $notes);
            $this->auditOpening($session, $user);
            return $session->fresh();
        }, 3);
    }

    private function createOpenSession(User $user, int $openingMinor, ?int $squareId, ?string $notes): CashSession
    {
        User::query()->lockForUpdate()->findOrFail($user->id);
        if ($this->openSessionFor($user)) {
            throw ValidationException::withMessages([
                'cash_session' => ['لديك صندوق مفتوح بالفعل. أغلقه قبل فتح صندوق جديد.'],
            ]);
        }
        $session = CashSession::create([
            'user_id' => $user->id,
            'square_id' => $squareId,
            'session_date' => now('Asia/Aden')->toDateString(),
            'opening_amount' => Cash::sessionDecimal($openingMinor),
            'settlement_basis' => 'physical_cash',
            'state' => CashSession::STATE_OPEN,
            'opened_at' => now(),
            'opening_notes' => $notes,
        ]);
        if (! $session->exists || $session->getKey() === null) {
            throw new \RuntimeException('Cash session opening was not persisted.');
        }
        return $session;
    }

    private function auditOpening(CashSession $session, User $actor): void
    {
        $this->requiredAudit('cash_session.opened', [
            'entity_type' => CashSession::class,
            'entity_id' => $session->id,
            'actor_user_id' => $actor->id,
            'after' => $this->summary($session->fresh()),
        ]);
    }

    /** Records cash already delivered, not authorization to disburse it. */
    public function openFunded(User $collector, User $issuer, int $accountId, string $amount,
        string $reference, string $evidence, ?int $squareId = null, ?string $notes = null,
        ?GatedTreasuryOpeningFundingWriter $gatedWriter = null): CashSession
    {
        app(TreasuryRegisterService::class)->authorize($issuer);
        abort_if((int) $collector->id === (int) $issuer->id, 403, 'يلزم مسؤول خزينة مستقل عن المحصّل.');
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $amount)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['funding' => 'يلزم مبلغ نقدي صحيح ومرجع ودليل تسليم مستقل.']);
        }
        $minor = Cash::input($amount, 'amount', DecimalDomain::D14, 1, digitsOnly: true);

        return DB::transaction(function () use ($collector, $issuer, $accountId, $minor, $reference, $evidence, $squareId, $notes, $gatedWriter): CashSession {
            // Same lock order as treasury posting and payment issuance: global register,
            // treasury account, then collector and new till. No subscriber lock is needed.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account, 404);
            $existing = DB::table('cash_session_fundings')->where('reference', $reference)->lockForUpdate()->first();
            $postingId = $existing === null ? null : DB::table('treasury_postings')->where('cash_session_funding_id', $existing->id)->value('id');
            $registered = $postingId !== null && DB::table('financial_posting_events')
                ->where('scope_key', 'water')->where('source_type', 'treasury_posting')->where('leg', 'treasury')
                ->where('source_id', (string) $postingId)->exists();
            if (! ($gatedWriter !== null && $gatedWriter->allowsFunding($accountId, (int) $collector->id, (int) $issuer->id, $reference))
                && ($gatedWriter !== null || $registered || config('financial_candidate.treasury_opening_funding') === true)) {
                throw new \DomainException('gated_collector_funding_writer_required');
            }
            if ($existing) {
                $session = CashSession::query()->findOrFail($existing->cash_session_id);
                abort_unless((int) $existing->treasury_account_id === $accountId
                    && (int) $existing->issued_by === (int) $issuer->id
                    && (int) $session->user_id === (int) $collector->id
                    && MoneyBoundary::readMinor($existing->amount_minor, true, DecimalDomain::D14) === $minor
                    && $existing->evidence === $evidence
                    && (int) ($session->square_id ?? 0) === (int) ($squareId ?? 0)
                    && ($session->opening_notes ?? '') === ($notes ?? ''), 409, 'مرجع التمويل مرتبط بعملية مختلفة.');
                $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $existing->id)->first();
                abort_unless($posting && MoneyBoundary::readMinor($posting->amount_minor, false, DecimalDomain::D14) === SignedMinor::negate($minor)
                    && (int) $posting->treasury_account_id === $accountId
                    && $posting->kind === 'collector_funding'
                    && $posting->evidence_reference === $reference
                    && $posting->effective_on === $existing->issued_on
                    && $this->sessionMinor($session, 'opening_amount') === $minor, 409, 'سجل التمويل غير متطابق؛ يلزم التدقيق.');
                return $session;
            }
            $today = now('Asia/Aden')->toDateString();
            if ($account->channel !== 'cash' || $account->opening_on > $today) {
                throw ValidationException::withMessages(['treasury_account_id' => 'يلزم حساب خزينة نقدية مفتوح.']);
            }
            abort_if(DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('cash_handovers')->where('reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
                || DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists(),
                409, 'مرجع الإثبات مستخدم مسبقاً.');
            app(HeldCashReservation::class)->assertOrdinaryOutflow($accountId, $minor, $today);
            $session = $this->createOpenSession($collector, $minor, $squareId, $notes);
            $funding = [
                'cash_session_id' => $session->id, 'treasury_account_id' => $accountId,
                'amount_minor' => $minor, 'reference' => $reference, 'evidence' => $evidence,
                'issued_by' => $issuer->id, 'issued_on' => $today,
            ];
            $fundingId = DB::table('cash_session_fundings')->insertGetId($funding + ['created_at' => now(), 'updated_at' => now()]);
            $postingId = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $accountId, 'cash_session_funding_id' => $fundingId,
                'kind' => 'collector_funding', 'amount_minor' => SignedMinor::negate($minor),
                'effective_on' => $today, 'evidence_reference' => $reference, 'posted_by' => $issuer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->requiredAudit('treasury.collector_funded', [
                'entity_type' => 'cash_session_funding', 'entity_id' => $fundingId,
                'actor_user_id' => $issuer->id,
                'after' => $funding + ['posting_id' => $postingId, 'subscriber_receipt_created' => false],
            ]);
            $this->auditOpening($session, $issuer);
            return $session->fresh();
        }, 3);
    }

    public function fundingSummary(CashSession $session): array
    {
        $opening = $this->sessionMinor($session, 'opening_amount');
        $funding = DB::table('cash_session_fundings')->where('cash_session_id', $session->id)->first();
        if (! $funding) {
            return ['status' => $opening === 0 ? 'not_required' : 'legacy_unverified', 'reference' => null, 'amount_minor' => null];
        }
        $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $funding->id)->first();
        $verified = $opening > 0 && MoneyBoundary::readMinor($funding->amount_minor, true, DecimalDomain::D14) === $opening
            && (int) $funding->issued_by !== (int) $session->user_id
            && $funding->issued_on === $session->session_date->toDateString()
            && $posting && $posting->kind === 'collector_funding'
            && MoneyBoundary::readMinor($posting->amount_minor, false, DecimalDomain::D14) === SignedMinor::negate($opening)
            && (int) $posting->treasury_account_id === (int) $funding->treasury_account_id
            && $posting->effective_on === $funding->issued_on && $posting->evidence_reference === $funding->reference;
        return ['status' => $verified ? 'documented' : 'inconsistent',
            'reference' => $funding->reference, 'amount_minor' => MoneyBoundary::readMinor($funding->amount_minor, true, DecimalDomain::D14)];
    }

    private function sessionMinor(CashSession $session, string $field, bool $signed = false, bool $nullable = false): ?int
    {
        return Cash::stored($session->getRawOriginal($field), $session->getConnection()->getDriverName(),
            DecimalDomain::D14, $signed, $nullable);
    }

    /** Financial decisions require a persisted event, including cancelled model saves. */
    private function requiredAudit(string $event, array $context): void
    {
        $record = $this->audit->recordRequired($event, $context);
        if (! $record->exists || $record->getKey() === null) {
            throw new \RuntimeException('Cash custody operation requires a persisted audit event.');
        }
    }

    /** Authoritative integer amount. Finalized physical sessions never recalculate. */
    public function expectedAmountMinor(CashSession $session): int
    {
        try {
            if (! $session->isOpen()) {
                $snapshot = $this->sessionMinor($session, 'expected_amount', nullable: true);
                if ($snapshot === null) {
                    throw new \InvalidArgumentException('Missing finalized expected amount.');
                }
                return $snapshot;
            }
            $driver = $session->getConnection()->getDriverName();
            $cash = Payment::query()
                ->where('cash_session_id', $session->id)
                ->where('payment_method', 'cash')
                ->whereNull('voided_at')
                ->orderBy('id')->get(['id', 'amount']);
            return Cash::expected($this->sessionMinor($session, 'opening_amount'),
                $cash->map(fn (Payment $payment) => Cash::positiveStored(
                    $payment->getRawOriginal('amount'), $driver, DecimalDomain::D12)));
        } catch (\InvalidArgumentException|\OverflowException) {
            throw ValidationException::withMessages(['expected_amount' => 'النقد المتوقع أو السجل المالي غير صالح أو خارج حدود التخزين.']);
        }
    }

    /** Compatibility numeric presentation only. */
    public function expectedAmount(CashSession $session): float
    {
        return MoneyBoundary::numericMajorOutput($this->expectedAmountMinor($session));
    }

    /** إغلاق الصندوق وتسجيل النقد المسلَّم والفرق. */
    public function close(CashSession $session, mixed $countedAmount, ?string $notes = null): CashSession
    {
        $countedMinor = Cash::input($countedAmount, 'counted_amount');
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'state' => ['لا يمكن إغلاق صندوق غير مفتوح.'],
            ]);
        }

        return DB::transaction(function () use ($session, $countedMinor, $notes) {
            User::query()->lockForUpdate()->findOrFail($session->user_id);
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['state' => ['لا يمكن إغلاق صندوق غير مفتوح.']]);
            }
            $expected = $this->expectedAmountMinor($session);
            $variance = Cash::variance($countedMinor, $expected);

            $before = $this->summary($session);
            $saved = $session->fill([
                'settlement_basis' => 'physical_cash',
                'expected_amount' => Cash::sessionDecimal($expected),
                'counted_amount' => Cash::sessionDecimal($countedMinor),
                'variance_amount' => Cash::sessionDecimal($variance, true),
                'state' => CashSession::STATE_CLOSED,
                'closed_at' => now(),
                'closing_notes' => $notes,
            ])->save();

            if (! $saved) {
                throw new \RuntimeException('Cash session closure was not persisted.');
            }
            $this->requiredAudit('cash_session.closed', [
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
            $saved = $session->fill([
                'state' => $approve ? CashSession::STATE_APPROVED : CashSession::STATE_REJECTED,
                'approved_at' => $approve ? now() : null,
                'approved_by' => $approver->id,
                'approval_notes' => $notes,
            ])->save();

            if (! $saved) {
                throw new \RuntimeException('Cash session decision was not persisted.');
            }
            $this->requiredAudit(
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

        $driver = $session->getConnection()->getDriverName();
        $totals = [];
        $cashMinor = 0;
        $totalMinor = 0;
        foreach ((clone $payments)->orderBy('id')->get(['id', 'payment_method', 'amount']) as $payment) {
            $method = $payment->payment_method;
            $minor = Cash::positiveStored($payment->getRawOriginal('amount'), $driver, DecimalDomain::D12);
            $totals[$method] ??= ['receipts' => 0, 'minor' => 0];
            ++$totals[$method]['receipts'];
            $totals[$method]['minor'] = SignedMinor::add($totals[$method]['minor'], $minor);
            $totalMinor = SignedMinor::add($totalMinor, $minor);
            if ($method === 'cash') {
                $cashMinor = SignedMinor::add($cashMinor, $minor);
            }
        }
        ksort($totals);
        $byMethod = [];
        foreach ($totals as $method => $total) {
            $byMethod[] = ['payment_method' => $method, 'receipts' => $total['receipts'],
                'total' => SignedMinor::decimal($total['minor'])];
        }
        $correctionTotal = 0;

        $voided = Payment::query()
            ->where('cash_session_id', $session->id)
            ->whereNotNull('voided_at')
            ->where(function ($query) { $query->whereNull('voided_via')->orWhere('voided_via', '!=', 'closed_correction'); })
            ->count();
        $corrections = DB::table('closed_cash_corrections')->where('cash_session_id', $session->id)->orderBy('id')->get();

        foreach ($corrections as $correction) {
            $correctionTotal = SignedMinor::add($correctionTotal,
                Cash::positiveStored($correction->amount, $driver, DecimalDomain::D12));
        }
        $counted = $this->sessionMinor($session, 'counted_amount', nullable: true);
        $variance = $this->sessionMinor($session, 'variance_amount', signed: true, nullable: true);
        $allocation = app(LateReceiptReviewService::class)->countedCashAllocation($session);
        $handover = app(CashHandoverService::class)->summary($session);

        return [
            'id' => $session->id,
            'state' => $session->state,
            'session_date' => optional($session->session_date)->format('Y-m-d'),
            'collector' => $session->user?->name,
            'square' => $session->square?->name,
            'opening_amount' => SignedMinor::decimal($this->sessionMinor($session, 'opening_amount')),
            'opening_funding' => $this->fundingSummary($session),
            'settlement_basis' => $session->isOpen() ? 'physical_cash' : ($session->settlement_basis ?? 'legacy_recorded'),
            'expected_amount' => SignedMinor::decimal($this->expectedAmountMinor($session)),
            'cash_collections' => SignedMinor::decimal($cashMinor),
            'non_cash_collections' => SignedMinor::decimal(SignedMinor::subtract($totalMinor, $cashMinor)),
            'total_collections' => SignedMinor::decimal($totalMinor),
            'counted_amount' => $counted === null ? null : SignedMinor::decimal($counted),
            'variance_amount' => $variance === null ? null : SignedMinor::decimal($variance),
            'counted_cash_allocation_eligible' => $allocation['eligible'],
            'counted_cash_surplus_capacity' => SignedMinor::decimal($allocation['capacity_minor']),
            'counted_cash_surplus_allocated' => SignedMinor::decimal($allocation['allocated_minor']),
            'counted_cash_surplus_available' => SignedMinor::decimal($allocation['available_minor']),
            'treasury_handover' => $handover,
            'receipts' => (clone $payments)->count(),
            'voided_receipts' => $voided,
            'post_close_corrections' => $corrections,
            'post_close_correction_total' => SignedMinor::decimal($correctionTotal),
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
