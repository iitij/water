<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** Read-only accounting projections. Never mutate posted documents. */
final class FinancialReportingService
{
    /** Current balances, independent of the invoice issue-date cohort. */
    public function accountBalances(?int $squareId = null): array
    {
        $accounts = Transaction::query()->join('subscribers', 'subscribers.id', '=', 'transactions.subscriber_id')
            ->whereNull('subscribers.deleted_at')
            ->when($squareId !== null, fn ($q) => $q->where('subscribers.square_id', $squareId))
            ->selectRaw('transactions.subscriber_id, SUM(transactions.amount) AS net_balance')
            ->groupBy('transactions.subscriber_id');
        $totals = DB::query()->fromSub($accounts, 'account_balances')
            ->selectRaw('SUM(CASE WHEN net_balance < 0 THEN -net_balance ELSE 0 END) AS debt,
                SUM(CASE WHEN net_balance > 0 THEN net_balance ELSE 0 END) AS credit')->first();
        return [
            'debt' => round((float) $totals->debt, 2),
            'credit' => round((float) $totals->credit, 2),
            'basis' => 'current_subscriber_ledger',
        ];
    }

    public function outstandingDebt(): float
    {
        return $this->accountBalances()['debt'];
    }

    /** Valid receipts by actual collection date, not accounting posting date. */
    public function receipts(string $from, string $to, ?int $squareId = null): Builder
    {
        return Payment::query()->active()
            ->where('payment_date', '>=', $from.' 00:00:00')
            ->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString().' 00:00:00')
            ->when($squareId !== null, fn ($q) => $q->whereHas('subscriber', fn ($s) => $s->where('square_id', $squareId)));
    }

    public function grossReceipts(string $from, string $to, ?int $squareId = null): float
    {
        return round((float) $this->receipts($from, $to, $squareId)->sum('amount'), 2);
    }

    /** Six calendar months; starting at day 1 avoids month-end overflow. */
    public function monthlyCollections(): array
    {
        $today = now();
        $months = [];
        for ($offset = 5; $offset >= 0; $offset--) {
            $month = $today->copy()->startOfMonth()->subMonths($offset);
            $months[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->locale('ar')->translatedFormat('F Y'),
                'value' => $this->grossReceipts($month->toDateString(),
                    $offset === 0 ? $today->toDateString() : $month->copy()->endOfMonth()->toDateString()),
            ];
        }
        return $months;
    }

    /**
     * Cash report uses disbursement date for refunds; ledger corrections use
     * posting date. A transfer is noncash and must not increase collection.
     */
    public function movements(string $from, string $to, ?int $squareId, float $grossCollection): array
    {
        $refunds = PaymentRefund::query()->whereDate('disbursed_on', '>=', $from)->whereDate('disbursed_on', '<=', $to);
        $ledger = Transaction::query()->where('created_at', '>=', $from.' 00:00:00')
            ->where('created_at', '<', Carbon::parse($to)->addDay()->toDateString().' 00:00:00');
        if ($squareId !== null) {
            $refunds->whereIn('subscriber_id', DB::table('subscribers')->where('square_id', $squareId)->select('id'));
            $ledger->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
        }
        $refunded = round((float) $refunds->sum('amount'), 2);
        $totals = $ledger->select('type')->selectRaw('SUM(amount) AS total')->groupBy('type')->pluck('total', 'type');
        return [
            'receipt_basis' => 'currently_valid_receipts_by_collection_date',
            'refund_basis' => 'disbursement_date',
            'ledger_basis' => 'posting_date',
            'gross_valid_receipts' => $grossCollection,
            'refunds_disbursed' => $refunded,
            'net_receipts_after_refunds' => round($grossCollection - $refunded, 2),
            'invoice_postings' => round(-(float) ($totals['invoice'] ?? 0), 2),
            'invoice_reversals' => round((float) ($totals['invoice_reversal'] ?? 0), 2),
            'receipt_reversals' => round(-(float) ($totals['payment_reversal'] ?? 0), 2),
            'account_transfers_in' => round((float) ($totals['payment_transfer_in'] ?? 0), 2),
            'ledger_net_movement' => round((float) $totals->sum(), 2),
        ];
    }
}
