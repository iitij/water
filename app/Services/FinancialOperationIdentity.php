<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Candidate shared UUID ownership check. Existing claims (even rejected)
 * retain their identifiers. A cleared claim/review and its resulting receipt
 * constitute one owner, not two independent operations.
 *
 * Scope-first registry callers and subscriber-first source callers must not
 * be enabled concurrently until the global locking protocol is reviewed.
 * This check is not a historical adoption or closure authorization.
 */
final class FinancialOperationIdentity
{
    public function __construct(
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $collection,
    ) {}

    /** New source documents only. Existing source replay is handled by its own service. */
    public function requireUnusedRegistryIdentity(string $uuid): void
    {
        $this->lock();
        if (DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first()) {
            abort(409, 'معرّف العملية مستخدم في السجل المالي.');
        }
    }

    /** Check the actual reserved owner after source creation, before registry insertion. */
    public function assertRegistryReservation(array $context, string $uuid, array $legs): void
    {
        $this->lock();
        $this->assertSourceIdentities($uuid, $legs);
        $payments = Payment::withTrashed()->where('sync_uuid', $uuid)->orderBy('id')->lockForUpdate()->get();
        $claims = DB::table('noncash_collection_claims')->where('sync_uuid', $uuid)->orderBy('id')->lockForUpdate()->get();
        $reviews = DB::table('late_receipt_reviews')->where('sync_uuid', $uuid)->orderBy('id')->lockForUpdate()->get();
        if ($payments->isEmpty() && $claims->isEmpty() && $reviews->isEmpty()) {
            return;
        }
        $this->need($context['scope'] === 'water' && $payments->count() === 1
            && $claims->count() + $reviews->count() <= 1, 'financial_uuid_reserved');
        $payment = $payments->first();
        $this->need(! $payment->trashed() && $payment->sync_uuid === $uuid
            && $payment->payment_date->toDateString() === $context['effective_on'],
            'financial_uuid_owner_mismatch');
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)->where('type', 'payment')->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'financial_uuid_owner_mismatch');
        $expected = [$this->ledger->leg((int) $entries->first()->id)];
        if ($claims->isNotEmpty()) {
            $row = $claims->first();
            $this->need($row->sync_uuid === $uuid && $row->status === 'cleared'
                && (int) $row->payment_id === (int) $payment->id
                && (int) $row->subscriber_id === (int) $payment->subscriber_id
                && in_array($payment->payment_method, ['check', 'bank_transfer'], true),
                'financial_uuid_owner_mismatch');
            $expected[] = $this->bankLeg($payment, $context['effective_on']);
        } elseif ($reviews->isNotEmpty()) {
            $row = $reviews->first();
            $this->need($row->sync_uuid === $uuid && $row->status === 'approved'
                && (int) $row->payment_id === (int) $payment->id
                && (int) $row->subscriber_id === (int) $payment->subscriber_id
                && $payment->cash_session_id === null, 'financial_uuid_owner_mismatch');
            if ($row->settlement_method === 'verified_non_cash') {
                $expected[] = $this->bankLeg($payment, $context['effective_on']);
            } elseif ($row->settlement_method === 'separate_handover') {
                $this->need($payment->payment_method === 'cash', 'financial_uuid_owner_mismatch');
                $doc = DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $row->id)->lockForUpdate()->first();
                $this->need($doc !== null, 'financial_uuid_owner_mismatch');
                $expected[] = $this->treasuryLeg('treasury_late_cash_receipt_id', (int) $doc->id, $context['effective_on']);
            } else {
                $this->need($row->settlement_method === 'already_counted' && $payment->payment_method === 'cash'
                    && ! DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $row->id)->exists(),
                    'financial_uuid_owner_mismatch');
            }
        } else {
            $this->need($payment->payment_method === 'cash', 'financial_uuid_owner_mismatch');
        }
        $this->need($this->canonical($expected) === $this->canonical($legs), 'financial_uuid_effect_mismatch');
    }

    /**
     * Reverse direction: a free operation UUID is not permission to relabel
     * an actual receipt or its original collection effect. Reversals, refunds
     * and account transfers are distinct operations, not original issuance.
     * The generic registry still supports nonbusiness evidence fixtures;
     * absent sources and receipts without a sync UUID require separate
     * source coverage/transition validation, not inferred ownership here.
     */
    private function assertSourceIdentities(string $uuid, array $legs): void
    {
        foreach ($legs as $leg) {
            $sourceId = $leg['source_id'];
            if (preg_match('/\A[1-9][0-9]*\z/', $sourceId) !== 1 || (string) (int) $sourceId !== $sourceId) {
                continue;
            }
            $paymentId = null;
            if ($leg['source_type'] === 'ledger_transaction') {
                $entry = Transaction::withTrashed()->whereKey((int) $sourceId)->lockForUpdate()->first();
                if ($entry !== null && $entry->type === 'payment') {
                    $this->need(! $entry->trashed() && $entry->reference_type === Payment::class
                        && (int) $entry->reference_id > 0, 'financial_uuid_owner_mismatch');
                    $paymentId = (int) $entry->reference_id;
                }
            } elseif ($leg['source_type'] === 'treasury_posting') {
                $posting = DB::table('treasury_postings')->where('id', (int) $sourceId)->lockForUpdate()->first();
                if ($posting !== null && $posting->kind === 'bank_collection') {
                    $doc = DB::table('treasury_bank_collections')->where('id', $posting->treasury_bank_collection_id)
                        ->lockForUpdate()->first();
                    $this->need($doc !== null && (int) $doc->payment_id > 0, 'financial_uuid_owner_mismatch');
                    $paymentId = (int) $doc->payment_id;
                } elseif ($posting !== null && $posting->kind === 'late_cash_receipt') {
                    $doc = DB::table('treasury_late_cash_receipts')->where('id', $posting->treasury_late_cash_receipt_id)
                        ->lockForUpdate()->first();
                    $review = $doc === null ? null : DB::table('late_receipt_reviews')
                        ->where('id', $doc->late_receipt_review_id)->lockForUpdate()->first();
                    $this->need($review !== null && (int) $review->payment_id > 0
                        && $review->sync_uuid === $uuid, 'financial_uuid_source_mismatch');
                    $paymentId = (int) $review->payment_id;
                }
            }
            if ($paymentId === null) {
                continue;
            }
            $payment = Payment::withTrashed()->whereKey($paymentId)->lockForUpdate()->first();
            $this->need($payment !== null && ! $payment->trashed(), 'financial_uuid_owner_mismatch');
            $this->need($payment->sync_uuid === null || $payment->sync_uuid === $uuid, 'financial_uuid_source_mismatch');
            foreach (['noncash_collection_claims', 'late_receipt_reviews'] as $table) {
                $owners = DB::table($table)->where('payment_id', $paymentId)->orderBy('id')->lockForUpdate()->get();
                foreach ($owners as $owner) {
                    $this->need($owner->sync_uuid === $uuid && $payment->sync_uuid === $uuid,
                        'financial_uuid_source_mismatch');
                }
            }
        }
    }

    private function bankLeg(Payment $payment, string $date): array
    {
        $doc = DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->lockForUpdate()->first();
        $this->need($doc !== null, 'financial_uuid_owner_mismatch');
        return $this->treasuryLeg('treasury_bank_collection_id', (int) $doc->id, $date);
    }

    private function treasuryLeg(string $foreignKey, int $id, string $date): array
    {
        $postings = DB::table('treasury_postings')->where($foreignKey, $id)->lockForUpdate()->get();
        $this->need($postings->count() === 1 && $postings->first()->effective_on === $date, 'financial_uuid_owner_mismatch');
        return $this->collection->leg((int) $postings->first()->id);
    }

    private function canonical(array $legs): array
    {
        $result = array_map(fn ($leg) => [
            'source_type' => $leg['source_type'], 'source_id' => $leg['source_id'], 'leg' => $leg['leg'],
            'amount_minor' => (string) $leg['amount_minor'], 'currency' => $leg['currency'],
            'source_hash' => $leg['source_hash'],
        ], $legs);
        usort($result, fn ($a, $b) => strcmp($a['source_type'].'/'.$a['source_id'].'/'.$a['leg'],
            $b['source_type'].'/'.$b['source_id'].'/'.$b['leg']));
        return $result;
    }

    private function lock(): void
    {
        $this->need(DB::transactionLevel() > 0, 'financial_uuid_requires_transaction');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'financial_uuid_issuance_lock_missing');
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
