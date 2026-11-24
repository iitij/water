<?php

namespace App\Services;

use App\Models\Subscriber;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One posting-date projection for JSON and exported account statements. */
final class LedgerStatementService
{
    private function minor($value): int
    {
        return (int) round((float) $value * 100);
    }

    public function requireMatchingSnapshot(array $statement, ?string $expectedHash): void
    {
        if ($expectedHash !== null && ! hash_equals($statement['statement_hash'], $expectedHash)) {
            abort(409, 'تغير كشف الحساب بعد عرضه؛ حدّث الكشف قبل التصدير.');
        }
    }

    public function statement(Subscriber $subscriber, string $from, string $to): array
    {
        if ($from > $to) {
            throw ValidationException::withMessages(['to_date' => 'تاريخ النهاية يسبق تاريخ البداية.']);
        }
        $start = $from.' 00:00:00';
        $end = Carbon::parse($to)->addDay()->toDateString().' 00:00:00';

        return DB::transaction(function () use ($subscriber, $from, $to, $start, $end): array {
            $account = Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            $ledger = Transaction::query()->where('subscriber_id', $account->id);
            $undated = (clone $ledger)->whereNull('created_at');
            $undatedCount = (clone $undated)->count();
            // Without a posting date a historic opening balance cannot be certified.
            // Do not silently assign such rows to any period, even if their net is zero.
            if ($undatedCount > 0) {
                throw ValidationException::withMessages([
                    'ledger' => 'توجد حركات مالية بلا تاريخ ترحيل؛ يلزم تدقيقها قبل إصدار كشف تاريخي.',
                ]);
            }
            $opening = $this->minor((clone $ledger)->where('created_at', '<', $start)->sum('amount'));
            $running = $opening;
            $debit = 0;
            $credit = 0;
            $rows = [];
            foreach ((clone $ledger)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->with('meter:id,unique_code')->orderBy('created_at')->orderBy('id')->get() as $entry) {
                $amount = $this->minor($entry->amount);
                $running += $amount;
                $debit += max(0, -$amount);
                $credit += max(0, $amount);
                $rows[] = [
                    'id' => $entry->id,
                    'posting_date' => $entry->created_at->format('Y-m-d H:i:s'),
                    'type' => $entry->type,
                    'reference_type' => $entry->reference_type,
                    'reference_id' => $entry->reference_id,
                    'description' => $entry->description,
                    'meter_code' => $entry->meter?->unique_code ?? $entry->retired_meter_code,
                    'debit_minor' => max(0, -$amount),
                    'credit_minor' => max(0, $amount),
                    'amount_minor' => $amount,
                    'running_balance_minor' => $running,
                ];
            }
            $closing = $this->minor((clone $ledger)->where('created_at', '<', $end)->sum('amount'));
            if ($running !== $closing || $opening + $credit - $debit !== $closing) {
                throw new \RuntimeException('Ledger statement does not conserve the recorded balance.');
            }
            $statement = [
                'subscriber' => ['id' => $account->id, 'name' => $account->name],
                'from_date' => $from, 'to_date' => $to,
                'basis' => 'ledger_posting_date',
                'sign_convention' => 'positive_credit_negative_debt',
                'opening_balance_minor' => $opening,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'closing_balance_minor' => $closing,
                'closing_debt_minor' => max(0, -$closing),
                'closing_credit_minor' => max(0, $closing),
                'entry_count' => count($rows),
                'entries' => $rows,
            ];
            return $statement + ['statement_hash' => hash('sha256', json_encode($statement, JSON_THROW_ON_ERROR))];
        }, 3);
    }
}
