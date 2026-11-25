<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Trusted server-side boundary. Deliberately has no production implementation
 * or container binding yet. Never instantiate from HTTP/request-provided facts.
 */
interface FinancialCloseProofSource
{
    /** Revalidate current active actor and independent capability under the gate. */
    public function authorize(string $scope, string $capability, int $actorId): bool;

    /**
     * Derive facts from actual locked, authoritative source data. In production
     * this must prove complete writer coverage, predecessor/opening evidence,
     * carries and current snapshot; no synthetic or caller-submitted flags.
     *
     * Returns ['facts' => array, 'snapshot' => array (prepare only)].
     */
    public function evidence(string $action, array $period, int $actorId): array;
}
