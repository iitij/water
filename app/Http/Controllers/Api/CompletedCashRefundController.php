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

    public function show(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        try {
            return response()->json($service->inspect($request->user(), strtolower($uuid)));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function close(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'decision' => ['required', 'in:rejected,withdrawn'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'], 'actor_id' => ['prohibited'],
        ]);
        try {
            return response()->json($service->close($request->user(), strtolower($uuid),
                $data['decision'], $data['reason'], now()->toDateTimeImmutable()));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function proposeResolution(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'count_id' => ['required', 'integer', 'min:1'],
            'supersedes_id' => ['sometimes', 'integer', 'min:1'],
            'classification' => ['required', 'in:no_disbursement,cash_returned,posting_required'],
            'evidence' => ['required', 'string', 'min:20', 'max:2000'],
            'actor_id' => ['prohibited'], 'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'],
            'amount' => ['prohibited'], 'account_id' => ['prohibited'], 'reference' => ['prohibited'],
        ]);
        try {
            return response()->json($service->proposeResolution($request->user(), strtolower($uuid),
                (int) $data['count_id'], $data['classification'], $data['evidence'], now()->toDateTimeImmutable(),
                isset($data['supersedes_id']) ? (int) $data['supersedes_id'] : null));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function approveResolution(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'min:20', 'max:2000'],
            'proposal_id' => ['sometimes', 'integer', 'min:1'],
            'actor_id' => ['prohibited'], 'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'],
            'amount' => ['prohibited'], 'account_id' => ['prohibited'], 'reference' => ['prohibited'],
            'classification' => ['prohibited'], 'count_id' => ['prohibited'], 'evidence' => ['prohibited'],
        ]);
        try {
            return response()->json($service->approveResolution($request->user(), strtolower($uuid),
                $data['confirmation'], now()->toDateTimeImmutable(), isset($data['proposal_id']) ? (int) $data['proposal_id'] : null));
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function postResolution(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'min:20', 'max:2000'],
            'proposal_id' => ['sometimes', 'integer', 'min:1'],
            'actor_id' => ['prohibited'], 'reviewer_id' => ['prohibited'], 'requested_by' => ['prohibited'],
            'amount' => ['prohibited'], 'account_id' => ['prohibited'], 'reference' => ['prohibited'],
            'classification' => ['prohibited'], 'count_id' => ['prohibited'], 'evidence' => ['prohibited'],
            'operation_uuid' => ['prohibited'], 'date' => ['prohibited'], 'payment_id' => ['prohibited'],
        ]);
        try {
            return response()->json($service->postResolution($request->user(), strtolower($uuid),
                $data['confirmation'], now()->toDateTimeImmutable(), isset($data['proposal_id']) ? (int) $data['proposal_id'] : null));
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
