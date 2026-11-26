<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProspectiveSubscriberTransferEntry
{
    public function enabled(): bool
    {
        if (config('financial_candidate.subscriber_transfers') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return true;
    }

    public function transfer(User $actor, Payment $payment, array $data): array
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
        $data = Validator::make($data, [
            'to_subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'no_new_cash_movement' => ['required', 'accepted'],
            'operation_uuid' => ['nullable', 'uuid'],
        ])->validate();
        try {
            if ($this->enabled()) {
                Validator::make($data, ['operation_uuid' => ['required', 'uuid']])->validate();
                $result = app(GatedInvoiceTransferWriter::class)->transfer($actor, (int) $payment->id,
                    (int) $data['to_subscriber_id'], $data['reason'], $data['evidence'],
                    strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                return ['transfer' => $result['transfer']];
            }
            if (isset($data['operation_uuid'])) throw new DomainException('gated_subscriber_transfer_entry_disabled');
            return ['transfer' => app(PaymentAccountTransferService::class)->transfer(
                $payment, (int) $data['to_subscriber_id'], $actor, $data['reason'], $data['evidence'])];
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }
}
