<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Staging-only settlement entry boundary; source identity comes from stored claims. */
final class ProspectiveSettlementEntry
{
    private function enabled(string $flag): bool
    {
        if (config('financial_candidate.'.$flag) !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return true;
    }

    private function source(string $table, int $id): object
    {
        $row = DB::table($table)->find($id);
        abort_unless($row !== null, 404);
        return $row;
    }

    private function bankDate(int $statementId, int $lineId): string
    {
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->find($lineId);
        abort_unless($line !== null, 404);
        return $line->booked_on;
    }

    private function conflict(callable $action): array
    {
        try {
            return $action();
        } catch (DomainException $error) {
            throw new ConflictHttpException('تعذر اعتماد التسوية المالية: '.$error->getMessage(), $error);
        }
    }

    public function clearClaim(User $actor, int $id, array $data): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return $this->conflict(function () use ($actor, $id, $data): array {
            $statement = (int) $data['statement_id'];
            $line = (int) $data['line_id'];
            if (! $this->enabled('noncash_settlements')) {
                return app(PaymentService::class)->clearNonCashClaim($actor, $id, $statement, $line, $data['reference'], $data['evidence']);
            }
            $source = $this->source('noncash_collection_claims', $id);
            $result = app(GatedCollectionWriter::class)->clearClaim($actor, $id, $statement, $line,
                $this->bankDate($statement, $line), $data['reference'], $data['evidence'], $source->sync_uuid, now()->toDateTimeImmutable());
            unset($result['transaction_id'], $result['registry_created']);
            return $result;
        });
    }

    public function clearBank(User $actor, int $id, array $data): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return $this->conflict(function () use ($actor, $id, $data): array {
            $statement = (int) $data['statement_id'];
            $line = (int) $data['line_id'];
            if (! $this->enabled('noncash_settlements')) {
                return app(PaymentService::class)->clearLateNonCash($actor, $id, $statement, $line,
                    $data['evidence_reference'], $data['bank_clearance_reference'], $data['evidence']);
            }
            $source = $this->source('late_receipt_reviews', $id);
            $result = app(GatedCollectionWriter::class)->clearLateBank($actor, $id, $statement, $line,
                $this->bankDate($statement, $line), $data['evidence_reference'], $data['bank_clearance_reference'],
                $data['evidence'], $source->sync_uuid, now()->toDateTimeImmutable());
            unset($result['transaction_id'], $result['registry_created']);
            return $result;
        });
    }

    public function clearCash(User $actor, int $id, array $data): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return $this->conflict(function () use ($actor, $id, $data): array {
            $account = (int) $data['treasury_account_id'];
            if (! $this->enabled('late_cash_settlements')) {
                return app(LateReceiptReviewService::class)->receiveCash($id, $actor, $account,
                    $data['received_on'], $data['evidence_reference'], $data['evidence']);
            }
            $source = $this->source('late_receipt_reviews', $id);
            $result = app(GatedCollectionWriter::class)->receiveLateCash($actor, $id, $account,
                $data['received_on'], $data['evidence_reference'], $data['evidence'], $source->sync_uuid, now()->toDateTimeImmutable());
            unset($result['transaction_id'], $result['registry_created']);
            return $result;
        });
    }

    public function decide(User $actor, int $id, array $data): array
    {
        $reviews = app(LateReceiptReviewService::class);
        abort_unless($reviews->canDecide($actor), 403);
        return $this->conflict(function () use ($reviews, $actor, $id, $data): array {
            if (! $this->enabled('late_cash_settlements') || ! (bool) $data['approve']
                || ($data['settlement_method'] ?? null) !== 'already_counted') {
                return ['review' => $reviews->decide($id, $actor, $data)];
            }
            // Filament and HTTP callers share this boundary. A nullable modal
            // reference must be a validation error, never a typed argument error.
            $reference = $data['evidence_reference'] ?? null;
            if (! is_string($reference) || mb_strlen(trim($reference)) < 3 || mb_strlen(trim($reference)) > 150) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'evidence_reference' => 'يلزم مرجع تسوية مستقل وصالح.',
                ]);
            }
            $source = $this->source('late_receipt_reviews', $id);
            $result = app(GatedCountedCashWriter::class)->allocate($actor, $id, $source->payment_date,
                $reference, $data['evidence'], $source->sync_uuid, now()->toDateTimeImmutable());
            return ['review' => $result['review']];
        });
    }
}
