<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashSession;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared HTTP and Filament custody receipt boundary. */
final class ProspectiveHandoverEntry
{
    public function enabled(): bool
    {
        if (config('financial_candidate.cash_handovers') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return true;
    }

    public function receive(User $actor, CashSession $session, array $data): array
    {
        $service = app(CashHandoverService::class);
        abort_unless($service->canRecord($actor), 403);
        abort_if((int) $session->user_id === (int) $actor->id, 403);
        try {
            if ($this->enabled()) {
                app(TreasuryRegisterService::class)->authorize($actor);
                $data = Validator::make($data, [
                    'amount' => ['required', 'regex:/^\\d{1,12}(?:\\.\\d{1,2})?$/D'],
                    'reference' => ['required', 'string', 'max:150'],
                    'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                    'evidence' => ['required', 'string', 'min:10', 'max:2000'],
                    'treasury_account_id' => ['required', 'integer', 'min:1'],
                    'operation_uuid' => ['required', 'uuid'],
                ])->validate();
                $minor = Cash::input($data['amount'], 'amount', DecimalDomain::D14, 1);
                $result = app(GatedCashHandoverWriter::class)->receive(
                    $actor, (int) $session->id, (int) $data['treasury_account_id'], Cash::sessionDecimal($minor),
                    $data['reference'], $data['received_on'], $data['evidence'],
                    strtolower($data['operation_uuid']), now()->toDateTimeImmutable()
                );
                return ['handover' => $result['handover'], 'treasury_handover' => $service->summary($session->fresh()),
                    'posting' => $result['posting']];
            }
            // Do not silently drop a new client's explicit atomic posting intent.
            if (isset($data['treasury_account_id']) || isset($data['operation_uuid'])) {
                throw new DomainException('gated_handover_entry_disabled');
            }
            $handover = $service->record($session, $actor, $data['amount'],
                $data['reference'], $data['received_on'], $data['evidence']);
            return ['handover' => $handover, 'treasury_handover' => $service->summary($session->fresh())];
        } catch (DomainException $error) {
            throw new ConflictHttpException('تعذر اعتماد تسليم النقد: '.$error->getMessage(), $error);
        }
    }
}
