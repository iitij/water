<?php

namespace App\Http\Controllers\Api;

use App\Services\NonCashClaimService;
use App\Services\ProspectiveSettlementEntry;
use Illuminate\Http\Request;

final class NonCashClaimController extends ApiController
{
    public function index(Request $request, NonCashClaimService $service)
    {
        return response()->json($service->index($request->user()));
    }

    public function store(Request $request, NonCashClaimService $service)
    {
        $data = $request->validate([
            'sync_uuid' => ['required', 'uuid'], 'subscriber_id' => ['required', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'payment_method' => ['required', 'in:bank_transfer,check'],
            'instrument_reference' => ['required', 'string', 'max:150'], 'instrument_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['claim' => $service->submit($request->user(), $data)], 201);
    }

    public function clear(Request $request, int $id, ProspectiveSettlementEntry $service)
    {
        $data = $request->validate([
            'statement_id' => ['required', 'integer', 'min:1'], 'line_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:150'], 'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        return response()->json($service->clearClaim($request->user(), $id, $data));
    }

    public function reject(Request $request, int $id, NonCashClaimService $service)
    {
        $data = $request->validate(['evidence' => ['required', 'string', 'min:10', 'max:2000']]);
        return response()->json(['claim' => $service->reject($request->user(), $id, $data['evidence'])]);
    }
}
