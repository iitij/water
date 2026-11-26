<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Record independently evidenced completed disbursements, never authorize payment. */
final class ProspectiveRefundEntry
{
    public function enabled(): bool
    {
        if (config('financial_candidate.refunds') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return true;
    }

    public function record(User $actor, Payment $payment, array $data): array
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
        $data = Validator::make($data, [
            'amount' => ['required', 'regex:/^\\d{1,10}(?:\\.\\d{1,2})?$/D'],
            'disbursement_reference' => ['required', 'string', 'min:3', 'max:150'],
            'method' => ['required', 'in:treasury_cash,bank_transfer'],
            'disbursed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'disbursement_confirmed' => ['required', 'accepted'],
            'treasury_account_id' => ['nullable', 'integer', 'min:1'],
            'operation_uuid' => ['nullable', 'uuid'],
        ])->validate();
        try {
            if ($this->enabled()) {
                Validator::make($data, [
                    'treasury_account_id' => ['required', 'integer', 'min:1'],
                    'operation_uuid' => ['required', 'uuid'],
                ])->validate();
                $result = app(GatedRefundWriter::class)->record($actor, (int) $payment->id,
                    (int) $data['treasury_account_id'], (string) $data['amount'], $data['disbursement_reference'],
                    $data['method'], $data['disbursed_on'], $data['reason'], $data['evidence'],
                    strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                return ['refund' => $result['refund'], 'posting' => $result['posting']];
            }
            if (isset($data['treasury_account_id']) || isset($data['operation_uuid'])) {
                throw new DomainException('gated_refund_entry_disabled');
            }
            return ['refund' => app(PaymentRefundService::class)->record($payment, $actor,
                (string) $data['amount'], $data['disbursement_reference'], $data['method'], $data['disbursed_on'],
                $data['reason'], $data['evidence'])];
        } catch (DomainException $error) {
            throw new ConflictHttpException('تعذر تسجيل الصرف المثبت: '.$error->getMessage(), $error);
        }
    }
}
