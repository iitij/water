<?php

namespace App\Http\Controllers\Api;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Http\Request;

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
    public function __construct(
        private readonly CashSessionService $sessions,
    ) {
    }

    /** قائمة الجلسات: المحصّل يرى جلسته، والمشرف يرى الكل (مع فلترة). */
    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAll = $this->allowsApi(CashSession::class, 'viewAny') || $this->canApprove($user);

        $query = CashSession::query()
            ->with(['user:id,name', 'square:id,name', 'approver:id,name'])
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

        return $query->paginate($this->perPage($request))->through(
            fn (CashSession $session) => $this->sessions->summary($session),
        )->appends($request->query());
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
        $session = CashSession::with(['user:id,name', 'square:id,name', 'approver:id,name'])->findOrFail($id);
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
            'notes' => ['nullable', 'string', 'max:1000'],
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

    /** تقرير يومي جاهز للطباعة/المشاركة (JSON؛ PDF يُبنى في التطبيق من نفس البيانات). */
    public function report(Request $request, int $id)
    {
        $session = CashSession::with(['user:id,name', 'square:id,name', 'approver:id,name'])->findOrFail($id);
        $this->authorizeSessionAccess($request->user(), $session);

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
                'voided' => $payment->voided_at !== null,
                'void_reason' => $payment->void_reason,
                'time' => optional($payment->created_at)->format('H:i'),
            ])->values()->all();

        return response()->json([
            'cash_session' => $this->sessions->summary($session),
            'payments' => $payments,
            'generated_at' => now()->toIso8601String(),
        ]);
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
