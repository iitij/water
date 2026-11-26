<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\CompletedCashRefundRequest;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CompletedCashRefundController extends ApiController
{
    public function submit(Request $request, int $id, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'reference' => ['required', 'string', 'min:3', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['required', 'uuid'],
            'disbursement_confirmed' => ['required', 'accepted'],
            'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'], 'actor_id' => ['prohibited'],
        ]);
        try {
            return response()->json($service->submit($request->user(), [
                'payment_id' => $id, 'account_id' => (int) $data['account_id'],
                'amount' => $data['amount'], 'reference' => $data['reference'], 'date' => $data['date'],
                'reason' => $data['reason'], 'evidence' => $data['evidence'],
                'operation_uuid' => strtolower($data['operation_uuid']),
            ], now()->toDateTimeImmutable()));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function approve(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'min:20', 'max:2000'],
            'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'], 'actor_id' => ['prohibited'],
            'amount' => ['prohibited'], 'account_id' => ['prohibited'], 'payment_id' => ['prohibited'],
            'reference' => ['prohibited'], 'date' => ['prohibited'], 'reason' => ['prohibited'],
            'evidence' => ['prohibited'], 'operation_uuid' => ['prohibited'],
        ]);
        try {
            return response()->json($service->approve($request->user(), strtolower($uuid),
                $data['confirmation'], now()->toDateTimeImmutable()));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }
}
