<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashSession;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only initial treasury balance and collector funding evidence.
 * Caller must hold the scope-first transaction; no coverage or close authorization.
 */
final class TreasuryOpeningFundingEvidence
{
    public function opening(int $accountId, string $effectiveOn): array
    {
        $this->lock($accountId);
        $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $this->need($account !== null, 'treasury_opening_missing');
        $this->need($account->opening_on === $effectiveOn
            && in_array($account->channel, ['cash', 'bank'], true)
            && (int) $account->opened_by > 0
            && mb_strlen($account->opening_reference) >= 3
            && mb_strlen(trim($account->opening_evidence)) >= 10, 'treasury_opening_source_mismatch');
        $minor = TreasuryMoney::document($account->opening_minor);
        $bytes = [
            'format' => 1, 'account_id' => (string) $accountId, 'code' => $account->code,
            'channel' => $account->channel, 'effective_on' => $effectiveOn,
            'amount_minor' => (string) $minor, 'actor_id' => (string) $account->opened_by,
            'reference' => $account->opening_reference, 'evidence' => $account->opening_evidence,
            'currency' => 'YER',
        ];
        return $this->evidence('treasury_opening', $accountId, 'opening', $minor, $bytes);
    }

    public function funding(int $postingId, string $effectiveOn): array
    {
        $this->lock($postingId);
        $row = DB::table('treasury_postings')->where('id', $postingId)->lockForUpdate()->first();
        $this->need($row !== null && $row->kind === 'collector_funding'
            && $row->cash_session_funding_id !== null, 'treasury_funding_missing');
        foreach (['cash_handover_id', 'payment_refund_id', 'treasury_transfer_id', 'treasury_transfer_receipt_id',
            'treasury_bank_fee_id', 'treasury_cash_adjustment_id', 'treasury_bank_collection_id',
            'treasury_late_cash_receipt_id'] as $field) {
            $this->need($row->$field === null, 'treasury_funding_source_conflict');
        }
        $funding = DB::table('cash_session_fundings')->where('id', $row->cash_session_funding_id)->lockForUpdate()->first();
        $this->need($funding !== null, 'treasury_funding_missing');
        $account = DB::table('treasury_accounts')->where('id', $funding->treasury_account_id)->lockForUpdate()->first();
        $session = CashSession::query()->lockForUpdate()->find($funding->cash_session_id);
        $this->need($account !== null && $session !== null, 'treasury_funding_missing');
        $minor = TreasuryMoney::document($funding->amount_minor, true);
        $opening = Cash::positiveStored($session->getRawOriginal('opening_amount'),
            $session->getConnection()->getDriverName(), DecimalDomain::D14);
        $this->need($minor === $opening && (int) $funding->issued_by > 0 && (int) $session->user_id > 0
            && (int) $funding->issued_by !== (int) $session->user_id
            && $account->channel === 'cash' && $account->opening_on <= $effectiveOn
            && $funding->issued_on === $session->session_date->toDateString()
            && $funding->issued_on === $effectiveOn && $row->effective_on === $effectiveOn
            && (int) $row->treasury_account_id === (int) $funding->treasury_account_id
            && (int) $row->posted_by === (int) $funding->issued_by
            && $row->evidence_reference === $funding->reference
            && mb_strlen($funding->reference) >= 3 && mb_strlen(trim($funding->evidence)) >= 10
            && TreasuryMoney::signed($row->amount_minor) === -$minor, 'treasury_funding_source_mismatch');
        // No mutable session closing state or subsequent payments in opening proof.
        $bytes = [
            'format' => 1, 'posting_id' => (string) $postingId, 'kind' => $row->kind,
            'funding_id' => (string) $funding->id, 'account_id' => (string) $account->id,
            'session_id' => (string) $session->id, 'collector_id' => (string) $session->user_id,
            'issuer_id' => (string) $funding->issued_by, 'effective_on' => $effectiveOn,
            'amount_minor' => (string) -$minor, 'reference' => $funding->reference,
            'evidence' => $funding->evidence, 'currency' => 'YER',
        ];
        return $this->evidence('treasury_posting', $postingId, 'treasury', -$minor, $bytes);
    }

    private function lock(int $id): void
    {
        $this->need(DB::transactionLevel() > 0 && $id > 0, 'treasury_opening_funding_requires_gate');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'treasury_opening_funding_issuance_lock');
    }

    private function evidence(string $type, int $id, string $leg, int $minor, array $bytes): array
    {
        return ['source_type' => $type, 'source_id' => (string) $id, 'leg' => $leg,
            'amount_minor' => $minor, 'currency' => 'YER',
            'source_hash' => hash('sha256', json_encode($bytes, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
