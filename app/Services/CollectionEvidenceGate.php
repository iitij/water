<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Scope-first transaction for evidence-only collection submissions and rejections.
 * The observed instrument/receipt date is provenance, not a posting date.
 * Financial approvals remain with their dedicated posting writers.
 */
final class CollectionEvidenceGate
{
    public function run(User $actor, callable $authorize, callable $work): array
    {
        if (config('financial_candidate.collection_evidence') !== true) {
            return DB::transaction(fn () => $work($actor), 3);
        }
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $now = now()->toDateTimeImmutable();
        try {
            return app(FinancialWriteGate::class)->withinOpenPeriod('water',
                $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d'), $now,
                function () use ($actor, $authorize, $work): array {
                    if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                        throw new \LogicException('Payment issuance lock missing.');
                    }
                    $current = User::query()->lockForUpdate()->findOrFail($actor->id);
                    abort_if($current->isDisabled(), 403);
                    $current->setRelation('roles', $current->roles()->lockForUpdate()->get());
                    $current->setRelation('permissions', $current->permissions()->lockForUpdate()->get());
                    $authorize($current);
                    return $work($current);
                });
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }
}
