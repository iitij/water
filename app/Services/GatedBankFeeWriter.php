<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\TreasuryMoney;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive writer for independently evidenced bank charges. A charge is an
 * actual bank debit, not authorization to spend or a subscriber movement.
 */
final class GatedBankFeeWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private BankReconciliationService $bank,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private TreasuryAdjustmentEvidence $adjustments,
        private FinancialPostingRegistry $registry,
    ) {}

    public function record(User $actor, int $statementId, int $lineId, string $date,
        string $reference, string $reason, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $statementId, $lineId, $reference, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_bank_fee_issuance_lock');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $statement = DB::table('bank_statements')->where('id', $statementId)->lockForUpdate()->first();
                $line = DB::table('bank_statement_lines')->where('id', $lineId)->where('bank_statement_id', $statementId)->lockForUpdate()->first();
                $this->need($statement !== null && $line !== null && $line->booked_on === $context['effective_on']
                    && (int) $line->treasury_account_id === (int) $statement->treasury_account_id, 'gated_bank_fee_effective_date');
                $minor = TreasuryMoney::signed($line->amount_minor);
                $this->need($minor < 0, 'gated_bank_fee_debit_required');
                $account = DB::table('treasury_accounts')->where('id', $statement->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'bank', 'gated_bank_fee_destination');
                $this->registered($this->opening->opening((int) $account->id, $account->opening_on), $account->opening_on);
                $existing = DB::table('treasury_bank_fees')->where('bank_statement_line_id', $lineId)->lockForUpdate()->first();
                $op = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $existing !== null;
                $this->need($replay === ($op !== null), 'gated_bank_fee_operation_conflict');
                if ($replay) {
                    $posting = DB::table('treasury_postings')->where('treasury_bank_fee_id', $existing->id)->lockForUpdate()->first();
                    $this->need($posting !== null && $posting->kind === 'bank_fee', 'gated_bank_fee_operation_conflict');
                    $prior = $this->registered($this->adjustments->leg((int) $posting->id), $context['effective_on']);
                    $this->need($prior->operation_uuid === $uuid, 'gated_bank_fee_operation_conflict');
                }

                $result = $this->bank->recordFee($actor, $statementId, $lineId, $reference, $reason, $evidence);
                $posting = $result['posting'];
                $leg = $this->adjustments->leg((int) $posting['id']);
                $this->need($leg['amount_minor'] === $minor && $posting['kind'] === 'bank_fee'
                    && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === (int) $account->id
                    && (int) $posting['posted_by'] === (int) $actor->id, 'gated_bank_fee_effect_mismatch');
                $audit = \App\Models\AuditEvent::query()->where('event', 'treasury.bank_fee_recorded')
                    ->where('entity_type', 'treasury_bank_fee')->where('entity_id', (string) $result['fee']['id'])
                    ->lockForUpdate()->sole();
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && (int) ($audit->after['posting_id'] ?? 0) === (int) $posting['id']
                    && ($audit->after['subscriber_posting_created'] ?? null) === false, 'gated_bank_fee_audit');
                $created = $this->registry->record($context, $uuid, [$leg], $now);
                $this->need($created === ! $replay, 'gated_bank_fee_operation_conflict');
                return $result + ['registry_created' => $created];
            });
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($event->source_hash, $leg['source_hash']), 'gated_bank_fee_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_bank_fee_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
