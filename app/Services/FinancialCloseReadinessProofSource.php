<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Staging-only bridge to real, locked diagnostic evidence. This intentionally
 * cannot authorize a close: the diagnostic is not an independent opening,
 * complete writer-coverage or carry certification. No container binding.
 */
final class FinancialCloseReadinessProofSource implements FinancialCloseProofSource
{
    public function __construct(private readonly FinancialCloseEvidenceDiagnostic $diagnostic) {}

    public function authorize(string $scope, string $capability, int $actorId): bool
    {
        if (! app()->environment(['testing', 'staging']) || DB::transactionLevel() !== 1
            || $scope !== 'water' || ! in_array($capability, ['request', 'prepare', 'approve', 'abort'], true)) {
            return false;
        }
        $authenticated = auth('sanctum')->user();
        if (! $authenticated instanceof User || (int) $authenticated->id !== $actorId || $authenticated->isDisabled()) {
            return false;
        }
        $actor = User::query()->lockForUpdate()->find($actorId);
        return $actor !== null && ! $actor->isDisabled()
            && $actor->roles()->lockForUpdate()->get()->contains(fn ($role) => $role->name === 'super_admin' && $role->guard_name === 'web');
    }

    public function evidence(string $action, array $period, int $actorId): array
    {
        $capability = match ($action) {
            'freeze' => 'request',
            'approve_replay' => 'approve',
            default => $action,
        };
        if (! $this->authorize($period['scope'] ?? '', $capability, $actorId)) {
            throw new DomainException('close_unauthorized');
        }
        $row = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('month', $period['month'])->lockForUpdate()->sole();
        if ($row->state !== $period['state'] || (int) $row->version !== $period['version']
            || $row->start_on !== $period['start'] || $row->next_on !== $period['next']) {
            throw new DomainException('close_readiness_state_mismatch');
        }
        $report = $this->diagnostic->inspectLocked(User::query()->findOrFail($actorId), (int) $row->id, now()->toDateTimeImmutable());
        // No caller-provided facts, no synthetic flags, no substitution of
        // current balances for closing balances. Explicitly fail even if a
        // later diagnostic accidentally returns an empty blockers array.
        throw new DomainException('close_readiness_unverified:'.implode(',', $report['blockers']));
    }
}
