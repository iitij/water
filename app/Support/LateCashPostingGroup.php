<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Pure canonical format 2 contract for separate late cash receipt only.
 * No database writes; caller must derive both legs from actual source rows
 * and independently validate period locks. A hash is not closure authority.
 * Format 1 registry bytes are unchanged.
 */
final class LateCashPostingGroup
{
    public function build(string $uuid, int $reviewId, int $paymentId, int $receiptId,
        array $subscriberContext, array $treasuryContext, array $subscriberLeg, array $treasuryLeg): array
    {
        $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1
            && $reviewId > 0 && $paymentId > 0 && $receiptId > 0, 'late_cash_group_identity');
        $a = $this->context($subscriberContext);
        $b = $this->context($treasuryContext);
        $this->need($a['effective_on'] < $b['effective_on'], 'late_cash_group_distinct_ordered_dates');
        $this->need($a['epoch'] === $b['epoch'], 'late_cash_group_epoch');
        $sameMonth = substr($a['effective_on'], 0, 7) === substr($b['effective_on'], 0, 7);
        $this->need($sameMonth
            ? $a['period_id'] === $b['period_id'] && $a['version'] === $b['version']
            : $a['period_id'] !== $b['period_id'], 'late_cash_group_period_identity');
        $subscriber = $this->leg($subscriberLeg, 'ledger_transaction', 'subscriber');
        $treasury = $this->leg($treasuryLeg, 'treasury_posting', 'treasury');
        $this->need($subscriber['amount_minor'] === $treasury['amount_minor'], 'late_cash_group_amount_mismatch');
        $payload = [
            'format' => 2, 'kind' => 'separate_late_cash', 'operation_uuid' => $uuid, 'scope' => 'water',
            'review_id' => (string) $reviewId, 'payment_id' => (string) $paymentId,
            'receipt_id' => (string) $receiptId, 'currency' => 'YER',
            'parts' => [
                ['role' => 'subscriber', 'context' => $a, 'leg' => $subscriber],
                ['role' => 'treasury', 'context' => $b, 'leg' => $treasury],
            ],
        ];
        $bytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['payload' => $payload, 'canonical_payload' => $bytes, 'payload_hash' => hash('sha256', $bytes)];
    }

    /** Compare persisted bytes against independently reconstructed proof. */
    public function assertMatches(array $actual, string $storedBytes, string $storedHash): void
    {
        $this->need(is_string($actual['canonical_payload'] ?? null) && is_string($actual['payload_hash'] ?? null)
            && hash_equals(hash('sha256', $actual['canonical_payload']), $actual['payload_hash'])
            && $storedBytes === $actual['canonical_payload']
            && hash_equals($actual['payload_hash'], $storedHash), 'late_cash_group_proof_mismatch');
    }

    private function context(array $value): array
    {
        $this->need(count($value) === 5 && ($value['scope'] ?? null) === 'water'
            && is_int($value['period_id'] ?? null) && $value['period_id'] > 0
            && is_int($value['epoch'] ?? null) && $value['epoch'] >= 0
            && is_int($value['version'] ?? null) && $value['version'] >= 0
            && is_string($value['effective_on'] ?? null), 'late_cash_group_context');
        $date = $value['effective_on'];
        $this->need(preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $date) === 1, 'late_cash_group_date');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $this->need($parsed !== false && $parsed->format('Y-m-d') === $date, 'late_cash_group_date');
        return ['period_id' => $value['period_id'], 'scope' => 'water', 'effective_on' => $date,
            'epoch' => $value['epoch'], 'version' => $value['version']];
    }

    private function leg(array $value, string $type, string $role): array
    {
        $id = $value['source_id'] ?? null;
        $minor = $value['amount_minor'] ?? null;
        $hash = $value['source_hash'] ?? null;
        $this->need(count($value) === 6 && ($value['source_type'] ?? null) === $type
            && ($value['leg'] ?? null) === $role && ($value['currency'] ?? null) === 'YER'
            && is_string($id) && preg_match('/\A[1-9][0-9]*\z/', $id) === 1 && (string) (int) $id === $id
            && is_int($minor) && $minor > 0 && $minor <= 999999999999
            && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1, 'late_cash_group_leg');
        return ['source_type' => $type, 'source_id' => $id, 'leg' => $role,
            'amount_minor' => (string) $minor, 'currency' => 'YER', 'source_hash' => $hash];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
