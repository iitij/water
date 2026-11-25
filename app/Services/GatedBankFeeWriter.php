<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\TreasuryMoney;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive bank-fee writer. The bank booking date, not the import or review
 * date, determines the financial period. Never adopts an old fee.
 * No payment initiation or subscriber posting.
 */
final class GatedBankFeeWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private BankReconciliationService $bank,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private TreasuryAdjustmentEvidence $evidence,
        private FinancialPostingRegistry $registry,
    ) {}

    public function record(User $actor, int $statementId, int $lineId, string $bookedOn,
        string $reference, string $reason, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $bookedOn, $now,
            function (array $context) use ($actor, $statementId, $lineId, $reference, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_bank_fee_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $statement = DB::table('bank_statements')->where('id', $statementId)->lockForUpdate()->first();
                $this->need($statement !== null, 'gated_bank_fee_statement_missing');
                abort_if((int) $statement->imported_by === (int) $actor->id, 403);
                $account = DB::table('treasury_accounts')->where('id', $statement->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'bank', 'gated_bank_fee_account');
                $lines = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $line = $lines->get($lineId);
                $this->need($line !== null && (int) $line->treasury_account_id === (int) $account->id
                    && $line->booked_on === $context['effective_on'], 'gated_bank_fee_line_date');
                $minor = TreasuryMoney::signed($line->amount_minor);
                $this->need($minor < 0, 'gated_bank_fee_debit_required');

                $opening = $this->opening->opening((int) $account->id, $account->opening_on);
                $op = $this->registered($opening, $account->opening_on);
                $this->registry->verifyPeriod('water', (int) $op->period_id);
                $import = AuditEvent::query()->where('event', 'bank_statement.imported')
                    ->where('entity_type', 'bank_statement')->where('entity_id', (string) $statementId)->lockForUpdate()->sole();
                $this->need((int) $import->actor_user_id === (int) $statement->imported_by
                    && ($import->after['content_hash'] ?? null) === $statement->content_hash
                    && ($import->after['line_count'] ?? null) === $lines->count(), 'gated_bank_fee_import_mismatch');

                $existing = DB::table('treasury_bank_fees')->where('bank_statement_line_id', $lineId)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($existing !== null) {
                    $posting = DB::table('treasury_postings')->where('treasury_bank_fee_id', $existing->id)->lockForUpdate()->first();
                    $this->need($operation !== null && $posting !== null, 'gated_bank_fee_operation_conflict');
                    $previous = $this->registered($this->evidence->leg((int) $posting->id), $context['effective_on']);
                    $this->need($previous->operation_uuid === $uuid, 'gated_bank_fee_operation_conflict');
                } else {
                    $this->need($operation === null, 'gated_bank_fee_operation_conflict');
                }

                $result = $this->bank->recordFee($actor, $statementId, $lineId, $reference, $reason, $evidence);
                $fee = $result['fee'];
                $posting = $result['posting'];
                $leg = $this->evidence->leg((int) $posting['id']);
                $match = DB::table('bank_statement_matches')->where('bank_statement_line_id', $lineId)->lockForUpdate()->sole();
                $this->need($leg['amount_minor'] === $minor && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === (int) $account->id
                    && (int) $posting['posted_by'] === (int) $actor->id
                    && (int) $fee['bank_statement_line_id'] === $lineId
                    && (int) $match->treasury_posting_id === (int) $posting['id']
                    && $result['reconciliation']['statement_integrity_matches'] === true,
                    'gated_bank_fee_effect_mismatch');
                $audit = AuditEvent::query()->where('event', 'treasury.bank_fee_recorded')
                    ->where('entity_type', 'treasury_bank_fee')->where('entity_id', (string) $fee['id'])->lockForUpdate()->sole();
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && (int) ($audit->after['posting_id'] ?? 0) === (int) $posting['id']
                    && (int) ($audit->after['match_id'] ?? 0) === (int) $match->id
                    && ($audit->after['subscriber_posting_created'] ?? null) === false
                    && ($audit->after['amount_minor'] ?? null) === -$minor
                    && ($audit->after['charged_on'] ?? null) === $context['effective_on']
                    && ($audit->after['reason'] ?? null) === trim($reason)
                    && ($audit->after['evidence'] ?? null) === trim($evidence), 'gated_bank_fee_audit_mismatch');
                return $result + ['registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_bank_fee_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_bank_fee_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
