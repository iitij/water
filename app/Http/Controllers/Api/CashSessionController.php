<?php

namespace App\Http\Controllers\Api;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashSessionService;
use App\Support\ArabicNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * صندوق المحصّل: فتح، إغلاق، اعتماد، وتقرير يومي.
 *
 * الصلاحيات:
 * - الفتح والإغلاق: صلاحية إنشاء دفعة (`create:Payment`) أو صلاحية مخصّصة
 *   `cash_sessions.manage`.
 * - الاعتماد: `approve:CashSession` أو صلاحية `cash_sessions.approve` أو مدير.
 *
 * مرجع: خطة الإصدار 2.0 — الفصلان 5.2 و10.2.
 */
class CashSessionController extends ApiController
{
    /** تسميات طرق الدفع كما تظهر في التقرير الورقي. */
    private const METHOD_LABELS = [
        'cash' => 'نقداً',
        'bank_transfer' => 'حوالة بنكية',
        'check' => 'شيك',
    ];

    public function __construct(
        private readonly CashSessionService $sessions,
    ) {}

    /** قائمة الجلسات: المحصّل يرى جلسته، والمشرف يرى الكل (مع فلترة). */
    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAll = $this->allowsApi(CashSession::class, 'viewAny') || $this->canApprove($user);

        $query = CashSession::query()
            ->with(['user:id,name', 'square:id,name,code', 'approver:id,name'])
            ->latest('id');

        if (! $canViewAll) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('state')) {
            $query->where('state', (string) $request->query('state'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('session_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('session_date', '<=', $request->query('to_date'));
        }

        $this->applySorting($query, $request, self::LIST_SORTS['cash_sessions']);
        $page = $query->paginate($this->perPage($request))->through(
            fn (CashSession $session) => $this->sessions->summary($session),
        )->appends($request->query())->toArray();

        // Populate the web-equivalent collector filter without requiring the
        // unrelated permission to list/manage staff accounts.
        $collectors = User::query()->whereIn('id', CashSession::query()->select('user_id'));
        if (! $canViewAll) {
            $collectors->whereKey($user->id);
        }
        $page['filter_options']['collectors'] = $collectors->orderBy('name')->get(['id', 'name']);

        return response()->json($page);
    }

    /** الصندوق المفتوح الحالي للمستخدم، أو null. */
    public function current(Request $request)
    {
        $session = $this->sessions->openSessionFor($request->user());

        return response()->json([
            'cash_session' => $session ? $this->sessions->summary($session->load('user', 'square', 'approver')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorizeCollector($user);

        $data = $request->validate([
            'opening_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = $this->sessions->open(
            $user,
            (float) ($data['opening_amount'] ?? 0),
            $data['square_id'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'تم فتح الصندوق.',
            'cash_session' => $this->sessions->summary($session->load('user', 'square', 'approver')),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $session = CashSession::with(['user:id,name', 'square:id,name,code', 'approver:id,name'])->findOrFail($id);
        $this->authorizeSessionAccess($request->user(), $session);

        return response()->json(['cash_session' => $this->sessions->summary($session)]);
    }

    public function close(Request $request, int $id)
    {
        $session = CashSession::findOrFail($id);
        $user = $request->user();
        $this->authorizeSessionAccess($user, $session);

        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = $this->sessions->close(
            $session,
            (float) $data['counted_amount'],
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'تم إغلاق الصندوق وحساب الفرق.',
            'cash_session' => $this->sessions->summary($session->load('user', 'square', 'approver')),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $this->authorizeApprover($request->user());
        $session = CashSession::findOrFail($id);

        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'notes' => [
                Rule::requiredIf(fn () => ! $request->boolean('approve')),
                'nullable', 'string', 'max:1000',
                ...(! $request->boolean('approve') ? ['min:10'] : []),
            ],
        ]);

        $session = $this->sessions->decide(
            $session,
            (bool) $data['approve'],
            $request->user(),
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => $data['approve'] ? 'تم اعتماد الصندوق.' : 'تم رفض الصندوق.',
            'cash_session' => $this->sessions->summary($session->load('user', 'square', 'approver')),
        ]);
    }

    /**
     * تقرير يومي جاهز للطباعة/المشاركة (JSON).
     *
     * القيم المالية مصاغة نصّاً بنفس تنسيق الخادم حتى لا يختلف التطبيق عن
     * نسخة الـ PDF (`pdf/cash_session.blade.php`).
     */
    public function report(Request $request, int $id)
    {
        $session = CashSession::with(['user:id,name', 'square:id,name,code', 'approver:id,name'])->findOrFail($id);
        $this->authorizeSessionAccess($request->user(), $session);

        return response()->json($this->reportData($session));
    }

    /**
     * تقرير الصندوق اليومي PDF — نفس بيانات `report` لكن بتنسيق A4 عربي جاهز
     * للطباعة/المشاركة (بطاقة S4-06). البناء على الخادم لأن الخطوط وتشكيل
     * العربية وتفقيط المبلغ لا يمكن ضمانها على كل جهاز ميداني.
     */
    public function downloadPdf(Request $request, int $id)
    {
        $session = CashSession::with(['user:id,name', 'square:id,name,code', 'approver:id,name'])->findOrFail($id);
        $this->authorizeSessionAccess($request->user(), $session);

        $data = $this->reportData($session);

        return Pdf::view('pdf.cash_session', [
            'session' => $session,
            'summary' => $data['cash_session'],
            'payments' => $data['payments'],
            'generatedAt' => optional($data['generated_at'])->format('Y-m-d H:i'),
            'amountInWords' => $session->counted_amount === null
                ? null
                : ArabicNumber::spellout((float) $session->counted_amount),
            'methodLabels' => self::METHOD_LABELS,
        ])
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->format('A4')
            ->name('cash-session-'.$session->id.'-'.optional($session->session_date)->format('Y-m-d').'.pdf')
            ->download();
    }

    /** @return array{cash_session: array, payments: array, generated_at: Carbon} */
    public function correctDuplicate(Request $request, int $id)
    {
        $this->authorizeApprover($request->user());
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'duplicate_of_id' => ['required', 'integer', 'different:payment_id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'no_second_cash_receipt' => ['required', 'accepted'],
        ]);
        $payment = Payment::where('cash_session_id', $id)->findOrFail($data['payment_id']);
        $correction = app(\App\Services\ClosedCashCorrectionService::class)->correctDuplicate(
            $payment, $data['duplicate_of_id'], $request->user(), $data['reason'], $data['evidence']
        );
        return response()->json(['correction' => $correction]);
    }

    private function reportData(CashSession $session): array
    {
        $payments = Payment::query()
            ->with(['subscriber:id,name', 'meter:id,unique_code'])
            ->where('cash_session_id', $session->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'subscriber' => $payment->subscriber?->name,
                'meter' => $payment->meter?->unique_code,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'payment_method' => $payment->payment_method,
                'voided' => $payment->voided_at !== null && $payment->voided_via !== 'closed_correction',
                'corrected_after_close' => $payment->voided_via === 'closed_correction',
                'void_reason' => $payment->voided_via === 'closed_correction' ? null : $payment->void_reason,
                'time' => optional($payment->created_at)->format('H:i'),
            ])->values()->all();

        return [
            'cash_session' => $this->sessions->summary($session),
            'payments' => $payments,
            'generated_at' => now(),
        ];
    }

    private function authorizeCollector(User $user): void
    {
        if ($this->isAdminUser($user)
            || $user->can('cash_sessions.manage')
            || $this->allowsApi(Payment::class, 'create')) {
            return;
        }

        abort(403, 'ليست لديك صلاحية فتح صندوق تحصيل.');
    }

    private function canApprove(User $user): bool
    {
        return $this->isAdminUser($user)
            || $user->can('cash_sessions.approve')
            || $user->can('Approve:CashSession');
    }

    private function authorizeApprover(User $user): void
    {
        abort_unless($this->canApprove($user), 403, 'اعتماد الصندوق يتطلب صلاحية مشرف.');
    }

    private function authorizeSessionAccess(User $user, CashSession $session): void
    {
        if ((int) $session->user_id === (int) $user->id
            || $this->allowsApi(CashSession::class, 'viewAny')
            || $this->canApprove($user)) {
            return;
        }

        abort(403, 'لا تملك صلاحية عرض صندوق محصّل آخر.');
    }
}
