<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashHandover;
use App\Models\CashSession;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Conservative reserve for newly registered full-count surplus handovers.
 * Allocation to a subscriber never releases this reserve automatically.
 * Called under issuance lock; does not acquire a financial scope lock.
 */
final class HandoverSurplusReservation
{
    public function forAccount(int $accountId): int
    {
        $this->need(DB::transactionLevel() > 0
            && DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null);
        $postings = DB::table('treasury_postings')->where('treasury_account_id', $accountId)
            ->where('kind', 'cash_handover')->orderBy('id')->lockForUpdate()->get();
        $proofs = AuditEvent::query()->where('event', 'financial.handover_surplus_reserved')
            ->orderBy('id')->lockForUpdate()->get();
        $seen = [];
        $total = 0;
        foreach ($postings as $posting) {
            $source = CashHandover::query()->lockForUpdate()->find($posting->cash_handover_id);
            $this->need($source !== null);
            $session = CashSession::query()->lockForUpdate()->find($source->cash_session_id);
            $this->need($session !== null);
            $event = DB::table('financial_posting_events')->where('scope_key', 'water')
                ->where('source_type', 'treasury_posting')->where('source_id', (string) $posting->id)
                ->where('leg', 'treasury')->lockForUpdate()->first();
            $matches = $proofs->filter(fn ($p) => $p->entity_type === CashHandover::class
                && (string) $p->entity_id === (string) $source->id)->values();
            // Unregistered legacy transfers are not adopted into this contract.
            if ($event === null && $matches->isEmpty()) continue;
            $driver = $session->getConnection()->getDriverName();
            $read = fn (string $field, bool $signed = false) =>
                Cash::stored($session->getRawOriginal($field), $driver, DecimalDomain::D14, $signed, true);
            $variance = $read('variance_amount', true);
            $expected = $read('expected_amount');
            $counted = $read('counted_amount');
            $approval = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
                ->where('entity_id', (string) $session->id)->lockForUpdate()->sole();
            $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
                && $variance !== null && $expected !== null && $counted !== null
                && Cash::consistent($counted, $expected, $variance)
                && (int) $approval->actor_user_id === (int) $session->approved_by
                && ($approval->after['expected_amount'] ?? null) === SignedMinor::decimal($expected)
                && ($approval->after['counted_amount'] ?? null) === SignedMinor::decimal($counted)
                && ($approval->after['variance_amount'] ?? null) === SignedMinor::decimal($variance));
            if ($variance <= 0) {
                $this->need($matches->isEmpty());
                continue;
            }
            $this->need($event !== null && $matches->count() === 1
                && CashHandover::where('cash_session_id', $session->id)->count() === 1);
            $leg = app(TreasuryCustodyEvidence::class)->leg((int) $posting->id);
            $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
            $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $posting->effective_on
                && DB::table('financial_posting_events')->where('operation_uuid', $op->operation_uuid)->count() === 1
                && $leg['amount_minor'] === $counted && (string) $event->amount_minor === (string) $counted
                && $event->currency === 'YER' && hash_equals($leg['source_hash'], $event->source_hash));
            $proof = $matches->sole();
            $expectedProof = self::proof($source, $posting, $event->operation_uuid, $variance, (int) $approval->id);
            $this->need((int) $proof->actor_user_id === (int) $posting->posted_by && count($proof->after ?? []) === count($expectedProof));
            foreach ($expectedProof as $field => $value) {
                $this->need(array_key_exists($field, $proof->after ?? []) && $proof->after[$field] === $value);
            }
            $seen[(string) $proof->id] = true;
            $total = SignedMinor::add($total, $variance);
        }
        foreach ($proofs as $proof) {
            if ((int) ($proof->after['account_id'] ?? 0) === $accountId) {
                $this->need(isset($seen[(string) $proof->id]));
            }
        }
        return $total;
    }

    public static function proof(CashHandover $source, object $posting, string $uuid, int $variance, int $approvalId): array
    {
        return ['session_id' => (int) $source->cash_session_id, 'handover_id' => (int) $source->id,
            'account_id' => (int) $posting->treasury_account_id, 'posting_id' => (int) $posting->id,
            'approval_id' => $approvalId, 'operation_uuid' => $uuid, 'reserved_minor' => $variance];
    }

    private function need(bool $ok): void
    {
        if (! $ok) throw new DomainException('gated_handover_surplus_reserve_invalid');
    }
}
