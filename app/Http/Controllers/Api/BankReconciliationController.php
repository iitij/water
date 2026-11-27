<?php

namespace App\Http\Controllers\Api;

use App\Services\BankReconciliationService;
use App\Services\TreasuryRegisterService;
use Illuminate\Http\Request;

final class BankReconciliationController extends ApiController
{
    public function index(Request $request, int $id, BankReconciliationService $service)
    {
        return response()->json($service->index($request->user(), $id));
    }

    public function store(Request $request, int $id, BankReconciliationService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:150'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:today'],
            'opening_amount' => ['required', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D'],
            'closing_amount' => ['required', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'lines' => ['present', 'array', 'max:1000'],
            'lines.*.bank_reference' => ['required', 'string', 'max:150'],
            'lines.*.booked_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.amount' => ['required', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            return response()->json($service->import($request->user(), $id, $data));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function show(Request $request, int $id, BankReconciliationService $service)
    {
        return response()->json($service->inspect($request->user(), $id));
    }

    public function confirmCollection(Request $request, int $id, BankReconciliationService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'line_id' => ['required', 'integer', 'min:1'],
            'payment_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'amount' => ['prohibited'], 'effective_on' => ['prohibited'], 'account_id' => ['prohibited'],
        ]);
        try {
            return response()->json($service->confirmCollection($request->user(), $id, (int) $data['line_id'],
                (int) $data['payment_id'], $data['reference'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'يجب اعتماد التحصيل عبر مسار التسوية المالية: '.$error->getMessage(), $error);
        }
    }

    public function recordFee(Request $request, int $id, BankReconciliationService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'line_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['nullable', 'uuid'],
            'amount' => ['prohibited'], 'effective_on' => ['prohibited'], 'account_id' => ['prohibited'],
        ]);
        try {
            if (config('financial_candidate.bank_fees') === true
                || \Illuminate\Support\Facades\DB::table('financial_scopes')->where('scope_key', 'water')->exists()) {
                abort_unless(app()->environment(['testing', 'staging']), 503);
                $request->validate(['operation_uuid' => ['required', 'uuid']]);
                $line = \Illuminate\Support\Facades\DB::table('bank_statement_lines')
                    ->where('bank_statement_id', $id)->find((int) $data['line_id']);
                abort_unless($line !== null, 404);
                $result = app(\App\Services\GatedBankFeeWriter::class)->record(
                    $request->user(), $id, (int) $data['line_id'], $line->booked_on,
                    $data['reference'], $data['reason'], $data['evidence'],
                    strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                unset($result['registry_created']);
                return response()->json($result);
            }
            if (isset($data['operation_uuid'])) {
                throw new \DomainException('gated_bank_fee_entry_disabled');
            }
            return response()->json($service->recordFee($request->user(), $id, (int) $data['line_id'],
                $data['reference'], $data['reason'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function matchLine(Request $request, int $id, BankReconciliationService $service)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        $data = $request->validate([
            'line_id' => ['required', 'integer', 'min:1'],
            'posting_id' => ['required', 'integer', 'min:1'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        try {
            return response()->json($service->matchLine($request->user(), $id, (int) $data['line_id'],
                (int) $data['posting_id'], $data['evidence']));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }
}
