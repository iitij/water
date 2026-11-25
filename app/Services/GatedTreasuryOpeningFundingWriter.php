<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive writer candidate for treasury opening and actual collector funding.
 * Scope-first transaction. Never silently adopt legacy opening or funding rows.
 * All callers and the global lock order must be reviewed before activation.
 */
final class GatedTreasuryOpeningFundingWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private TreasuryRegisterService $accounts,
        private CashSessionService $sessions,
        private TreasuryOpeningFundingEvidence $evidence,
        private FinancialPostingRegistry $registry,
    ) {}

    public function open(User $actor, array $data, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $data['opening_on'], $now,
            function (array $context) use ($actor, $data, $uuid, $now): array {
                $this->lock($uuid);
                $existing = DB::table('treasury_accounts')->where('code', mb_strtoupper(trim($data['code'])))
                    ->lockForUpdate()->first();
                $this->checkReplay($uuid, 'treasury_opening', 'opening', $existing ? (int) $existing->id : null);
                $account = $this->accounts->open($actor, $data);
                $leg = $this->evidence->opening((int) $account['id'], $context['effective_on']);
                return ['account' => $account, 'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    /** Funding is a physical movement today, not a caller-selected historical date. */
    public function fund(User $collector, User $issuer, int $accountId, string $amount,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now,
        ?int $squareId = null, ?string $notes = null): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($collector, $issuer, $accountId, $amount, $reference, $evidence, $uuid, $now, $squareId, $notes): array {
                $this->lock($uuid);
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $this->need($account !== null, 'gated_funding_account_missing');
                $opening = $this->evidence->opening($accountId, $account->opening_on);
                $event = $this->registered('treasury_opening', 'opening', $accountId);
                $this->need($event !== null && (string) $event->amount_minor === (string) $opening['amount_minor']
                    && $event->currency === $opening['currency']
                    && hash_equals($opening['source_hash'], $event->source_hash), 'gated_funding_opening_unregistered');
                $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
                    ->lockForUpdate()->first();
                $this->need($op !== null && $op->scope_key === 'water'
                    && $op->effective_on === $account->opening_on, 'gated_funding_opening_unregistered');
                $this->registry->verifyPeriod('water', (int) $op->period_id);

                $existing = DB::table('cash_session_fundings')->where('reference', mb_strtoupper(trim($reference)))
                    ->lockForUpdate()->first();
                $posting = $existing ? $this->posting((int) $existing->id) : null;
                $this->checkReplay($uuid, 'treasury_posting', 'treasury', $posting ? (int) $posting->id : null);
                $session = $this->sessions->openFunded($collector, $issuer, $accountId, $amount,
                    $reference, $evidence, $squareId, $notes);
                $funding = DB::table('cash_session_fundings')->where('cash_session_id', $session->id)->lockForUpdate()->sole();
                $posting = $this->posting((int) $funding->id);
                $leg = $this->evidence->funding((int) $posting->id, $context['effective_on']);
                return ['session' => $session, 'posting_id' => (int) $posting->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function lock(string $uuid): void
    {
        $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'posting_uuid');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'gated_opening_funding_issuance_lock_missing');
    }

    private function posting(int $fundingId): object
    {
        return DB::table('treasury_postings')->where('cash_session_funding_id', $fundingId)->lockForUpdate()->sole();
    }

    private function registered(string $type, string $leg, int $id): ?object
    {
        return DB::table('financial_posting_events')->where('scope_key', 'water')->where('source_type', $type)
            ->where('source_id', (string) $id)->where('leg', $leg)->lockForUpdate()->first();
    }

    private function checkReplay(string $uuid, string $type, string $leg, ?int $id): void
    {
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
        if ($id === null) {
            $this->need($operation === null, 'gated_opening_funding_operation_conflict');
            return;
        }
        $event = $this->registered($type, $leg, $id);
        $this->need($operation !== null && $event !== null && $event->operation_uuid === $uuid,
            'gated_opening_funding_unregistered_or_conflicting_source');
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
