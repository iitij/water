<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * First narrow writer adapter: treasury transfers only.
 * Internal candidate, not wired to routes/commands until every writer is fenced.
 * Scope -> period -> issuance -> business rows. No historical adoption.
 * The caller supplies a stable operation UUID and trusted server clock.
 */
final class GatedTreasuryTransferWriter
{
    private ?array $mutationIdentity = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private TreasuryTransferService $transfers,
        private TreasuryTransferEvidence $evidence,
        private FinancialPostingRegistry $registry,
    ) {}

    public function dispatch(User $actor, array $data, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $data['dispatched_on'], $now,
            function (array $context) use ($actor, $data, $uuid, $now): array {
                $this->lock($uuid);
                $existing = DB::table('treasury_transfers')->where('reference', mb_strtoupper(trim($data['reference'])))
                    ->lockForUpdate()->first();
                $this->checkReplay($uuid, $existing ? $this->posting('treasury_transfer_id', (int) $existing->id) : null);
                $result = $this->mutate(['dispatch', mb_strtoupper(trim($data['reference'])), (int) $actor->id],
                    fn () => $this->transfers->dispatch($actor, $data, $this));
                return $this->record($context, $uuid, $now, $result,
                    $this->posting('treasury_transfer_id', (int) $result['id']));
            });
    }

    public function receive(User $actor, int $transferId, array $data, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $data['received_on'], $now,
            function (array $context) use ($actor, $transferId, $data, $uuid, $now): array {
                $this->lock($uuid);
                $transfer = DB::table('treasury_transfers')->where('id', $transferId)->lockForUpdate()->first();
                $this->need($transfer !== null, 'gated_transfer_dispatch_missing');
                $dispatch = $this->posting('treasury_transfer_id', $transferId);
                // No receiving a legacy dispatch through this candidate adapter.
                $dispatchLeg = $this->evidence->leg((int) $dispatch->id);
                $registered = DB::table('financial_posting_events')->where('scope_key', 'water')
                    ->where('source_type', 'treasury_posting')->where('source_id', $dispatchLeg['source_id'])
                    ->where('leg', 'treasury')->lockForUpdate()->first();
                $this->need($registered !== null
                    && (string) $registered->amount_minor === (string) $dispatchLeg['amount_minor']
                    && $registered->currency === $dispatchLeg['currency']
                    && hash_equals($dispatchLeg['source_hash'], $registered->source_hash), 'gated_transfer_dispatch_unregistered');
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $registered->operation_uuid)
                    ->lockForUpdate()->first();
                $this->need($operation !== null && $operation->scope_key === 'water'
                    && $operation->effective_on === $dispatch->effective_on, 'gated_transfer_dispatch_unregistered');
                $this->registry->verifyPeriod('water', (int) $operation->period_id);
                $existing = DB::table('treasury_transfer_receipts')->where('treasury_transfer_id', $transferId)
                    ->lockForUpdate()->first();
                $this->checkReplay($uuid, $existing ? $this->posting('treasury_transfer_receipt_id', (int) $existing->id) : null);
                $result = $this->mutate(['receive', (string) $transferId, (int) $actor->id],
                    fn () => $this->transfers->receive($actor, $transferId, $data, $this));
                return $this->record($context, $uuid, $now, $result,
                    $this->posting('treasury_transfer_receipt_id', (int) $result['receipt']['id']));
            });
    }

    public function allowsMutation(string $kind, string $identity, int $actorId): bool
    {
        return DB::transactionLevel() > 0 && $this->mutationIdentity === [$kind, $identity, $actorId];
    }

    private function mutate(array $identity, callable $action): array
    {
        $this->need($this->mutationIdentity === null && DB::transactionLevel() > 0, 'gated_transfer_mutation_scope');
        $this->mutationIdentity = $identity;
        try {
            return $action();
        } finally {
            $this->mutationIdentity = null;
        }
    }

    private function lock(string $uuid): void
    {
        $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'posting_uuid');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'gated_transfer_issuance_lock_missing');
    }

    private function posting(string $column, int $id): object
    {
        return DB::table('treasury_postings')->where($column, $id)->lockForUpdate()->sole();
    }

    private function checkReplay(string $uuid, ?object $posting): void
    {
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
        if ($posting === null) {
            $this->need($operation === null, 'gated_transfer_operation_conflict');
            return;
        }
        $registered = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', 'treasury_posting')->where('source_id', (string) $posting->id)
            ->where('leg', 'treasury')->lockForUpdate()->first();
        $this->need($operation !== null && $registered !== null && $registered->operation_uuid === $uuid,
            'gated_transfer_unregistered_or_conflicting_source');
    }

    private function record(array $context, string $uuid, DateTimeImmutable $now, array $result, object $posting): array
    {
        $this->need($posting->effective_on === $context['effective_on'], 'gated_transfer_date_mismatch');
        $leg = $this->evidence->leg((int) $posting->id);
        $created = $this->registry->record($context, $uuid, [$leg], $now);
        return ['transfer' => $result, 'posting_id' => (int) $posting->id, 'registry_created' => $created];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
