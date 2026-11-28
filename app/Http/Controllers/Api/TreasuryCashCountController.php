<?php

namespace App\Http\Controllers\Api;

use App\Services\TreasuryCashCountService;
use App\Services\TreasuryRegisterService;
use Illuminate\Http\Request;

final class TreasuryCashCountController extends ApiController
{
    public function index(Request $request, int $id, TreasuryCashCountService $service)
    {
        return response()->json($service->index($request->user(), $id));
    }

    public function show(Request $request, int $id, TreasuryCashCountService $service)
    {
        return response()->json($service->inspect($request->user(), $id));
    }

    public function store(Request $request, int $id, TreasuryCashCountService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'counted_amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'reference' => ['required', 'string', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'observed_at' => ['prohibited'],
        ]);
        try {
            return response()->json($service->record($request->user(), $id, (string) $data['counted_amount'],
                $data['reference'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function adjust(Request $request, int $id, TreasuryCashCountService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:150'],
            'classification' => ['required', 'in:confirmed_cash_loss,unattributed_cash_held'],
            'reason' => ['required', 'string', 'min:20', 'max:500'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['nullable', 'uuid'],
            'amount' => ['prohibited'], 'effective_on' => ['prohibited'], 'account_id' => ['prohibited'],
        ]);
        try {
            if (config('financial_candidate.cash_count_adjustments') === true
                || \Illuminate\Support\Facades\DB::table('financial_scopes')->where('scope_key', 'water')->exists()) {
                abort_unless(app()->environment(['testing', 'staging']), 503);
                $request->validate(['operation_uuid' => ['required', 'uuid']]);
                $result = app(\App\Services\GatedCashCountAdjustmentWriter::class)->adjust(
                    $request->user(), $id, $data['reference'], $data['classification'],
                    $data['reason'], $data['evidence'], strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                unset($result['registry_created']);
                return response()->json($result);
            }
            if (isset($data['operation_uuid'])) throw new \DomainException('gated_cash_count_adjustment_entry_disabled');
            return response()->json($service->adjust($request->user(), $id, $data['reference'],
                $data['classification'], $data['reason'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function review(Request $request, int $id, TreasuryCashCountService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        try {
            return response()->json($service->review($request->user(), $id, (bool) $data['accept'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }
}
