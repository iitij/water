<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\CompletedCashRefundRequest;
use App\Services\CustodyShortageLossDecision;
use App\Services\CustodyShortageRecoveryProposal;
use App\Services\CustodyShortageRecoveryWriter;
use App\Services\TreasuryRegisterService;
use App\Support\CashCustodyAmounts;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Candidate transport only. All decisions remain inside the financial write gate. */
final class CustodyShortageController extends ApiController
{
    public function proposeRecovery(Request $request, int $id, CustodyShortageRecoveryProposal $service)
    {
        $data = $this->input($request, [
            'account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            'reference' => ['required', 'string', 'min:3', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['required', 'uuid'],
        ]);
        $minor = CashCustodyAmounts::input($data['amount'], 'amount', minimum: 1, digitsOnly: true);
        return $this->respond(fn () => $service->propose($request->user(), $id, (int) $data['account_id'],
            $data['reference'], $data['evidence'], strtolower($data['operation_uuid']), now()->toDateTimeImmutable(), $minor));
    }

    public function approveRecovery(Request $request, int $id, CustodyShortageRecoveryWriter $service)
    {
        $data = $this->input($request, ['operation_uuid' => ['required', 'uuid']]);
        return $this->respond(fn () => $service->approve($request->user(), $id, now()->toDateTimeImmutable(),
            strtolower($data['operation_uuid'])));
    }

    public function proposeLoss(Request $request, int $id, CustodyShortageLossDecision $service)
    {
        $data = $this->input($request, [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        return $this->respond(fn () => $service->propose($request->user(), $id, $data['reason'],
            $data['evidence'], now()->toDateTimeImmutable()));
    }

    public function approveLoss(Request $request, int $id, CustodyShortageLossDecision $service)
    {
        $this->input($request, []);
        return $this->respond(fn () => $service->approve($request->user(), $id, now()->toDateTimeImmutable()));
    }

    private function input(Request $request, array $rules): array
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        CompletedCashRefundRequest::authenticated($request->user());
        app(TreasuryRegisterService::class)->authorize($request->user());
        // Reject all caller-supplied facts not in the operation contract, including
        // actor, approval identity, accounting date, and any replacement amount.
        foreach (array_diff(array_keys($request->all()), array_keys($rules)) as $field) {
            throw ValidationException::withMessages([$field => 'حقل غير مسموح في هذه العملية.']);
        }
        return $request->validate($rules);
    }

    private function respond(callable $action)
    {
        try {
            return response()->json($action());
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }
}
