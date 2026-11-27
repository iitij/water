<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Candidate adapter for real evidence inspection, not an operational close
 * capability. Deliberately unbound: never assert completeness from partial
 * diagnostic matches, including when the report contains no transactions.
 */
final class FinancialCloseReadinessProofSource implements FinancialCloseProofSource
{
    public function __construct(private readonly FinancialCloseEvidenceDiagnostic $diagnostic) {}

    public function authorize(string $scope, string $capability, int $actorId): bool
    {
        return $this->lockedActor($scope, $capability, $actorId) !== null;
    }

    private function lockedActor(string $scope, string $capability, int $actorId): ?User
    {
        if (! app()->environment(['testing', 'staging']) || DB::transactionLevel() !== 1
            || $scope !== 'water' || $actorId <= 0
            || ! in_array($capability, ['request', 'prepare', 'approve', 'abort'], true)) {
            return null;
        }
        $authenticated = auth('sanctum')->user();
        if (! $authenticated instanceof User || (int) $authenticated->id !== $actorId
            || $authenticated->isDisabled()) return null;
        $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
        if (! $gate || $gate->mode !== 'ACTIVE' || $gate->currency !== 'YER'
            || $gate->timezone !== 'Asia/Aden' || (string) $gate->calendar_version !== '1') return null;
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) return null;
        $actor = User::query()->lockForUpdate()->find($actorId);
        if (! $actor || $actor->isDisabled()) return null;
        $roles = $actor->roles()->lockForUpdate()->get();
        $actor->setRelation('roles', $roles);
        return $roles->contains(fn ($role) => $role->name === 'super_admin' && $role->guard_name === 'web')
            ? $actor : null;
    }

    public function evidence(string $action, array $period, int $actorId): array
    {
        $capability = match ($action) {
            'freeze' => 'request',
            'prepare', 'approve', 'abort' => $action,
            'approve_replay' => 'approve',
            default => throw new DomainException('close_action'),
        };
        $actor = $this->lockedActor($period['scope'] ?? '', $capability, $actorId);
        if ($actor === null) throw new DomainException('close_unauthorized');
        $row = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('month', $period['month'] ?? '')->lockForUpdate()->first();
        if (! $row || $row->state !== ($period['state'] ?? null)
            || (string) $row->version !== (string) ($period['version'] ?? '')
            || $row->start_on !== ($period['start'] ?? null) || $row->next_on !== ($period['next'] ?? null)) {
            throw new DomainException('close_readiness_period_mismatch');
        }
        $report = $this->diagnostic->inspectLocked($actor, (int) $row->id, now()->toDateTimeImmutable());
        // A diagnostic report is not a certified snapshot or authority to
        // freeze, abort, approve or replay approval. Keep every transition
        // blocked until the remaining proof and operational recovery exist.
        throw new DomainException('close_readiness_unverified:'.implode(',', $report['blockers']));
    }
}
