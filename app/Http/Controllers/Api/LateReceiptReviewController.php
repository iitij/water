<?php

namespace App\Http\Controllers\Api;

use App\Models\CashSession;
use App\Models\Payment;
use App\Services\LateReceiptReviewService;
use App\Services\ProspectiveSettlementEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LateReceiptReviewController extends ApiController
{
    public function store(Request $request, LateReceiptReviewService $service)
    {
        $this->authorizeApi(Payment::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'invoice_id' => ['nullable', 'integer'],
            'meter_id' => ['nullable', 'integer', 'min:1'],
            'cash_session_id' => ['required', 'integer', 'min:1'],
            'sync_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['review' => $service->submit($request->user(), $data)]);
    }

    public function index(Request $request, int $id, LateReceiptReviewService $service)
    {
        $session = CashSession::findOrFail($id);
        abort_unless((int) $session->user_id === (int) $request->user()->id || $service->canDecide($request->user()), 403);
        return response()->json(DB::table('late_receipt_reviews')->where('cash_session_id', $id)->orderByDesc('id')->paginate(25));
    }

    public function clearCash(Request $request, int $id, ProspectiveSettlementEntry $service)
    {
        app(\App\Services\TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'treasury_account_id' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'evidence_reference' => ['required', 'string', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'amount' => ['prohibited'],
        ]);
        return response()->json($service->clearCash($request->user(), $id, $data));
    }

    public function clearBank(Request $request, int $id, ProspectiveSettlementEntry $service)
    {
        app(\App\Services\TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'statement_id' => ['required', 'integer', 'min:1'],
            'line_id' => ['required', 'integer', 'min:1'],
            'evidence_reference' => ['required', 'string', 'max:150'],
            'bank_clearance_reference' => ['required', 'string', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        return response()->json($service->clearBank($request->user(), $id, $data));
    }

    public function decide(Request $request, int $id, LateReceiptReviewService $service)
    {
        abort_unless($service->canDecide($request->user()), 403);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence_reference' => ['required_if:approve,true', 'nullable', 'string', 'min:3', 'max:150'],
            'settlement_method' => ['required_if:approve,true', 'nullable', 'in:separate_handover,verified_non_cash,already_counted'],
        ]);
        return response()->json(app(ProspectiveSettlementEntry::class)->decide($request->user(), $id, $data));
    }
}
