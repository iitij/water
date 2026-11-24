<?php

namespace App\Services;

use App\Models\PaymentRefund;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** Read-only accounting projections. Never mutate posted documents. */
final class FinancialReportingService
{
    public function outstandingDebt(): float
    {
        $accounts = Transaction::query()->join('subscribers', 'subscribers.id', '=', 'transactions.subscriber_id')
            ->whereNull('subscribers.deleted_at')
            ->selectRaw('transactions.subscriber_id, SUM(transactions.amount) AS net_balance')
            ->groupBy('transactions.subscriber_id');
        return round((float) DB::query()->fromSub($accounts, 'account_balances')
            ->where('net_balance', '<', 0)->sum(DB::raw('-net_balance')), 2);
    }

    /**
     * Cash report uses disbursement date for refunds; ledger corrections use
     * posting date. A transfer is noncash and must not increase collection.
     */
    public function movements(string $from, string $to, ?int $squareId, float $grossCollection): array
    {
        $refunds = PaymentRefund::query()->whereDate('disbursed_on', '>=', $from)->whereDate('disbursed_on', '<=', $to);
        $ledger = Transaction::query()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($squareId !== null) {
            $refunds->whereIn('subscriber_id', DB::table('subscribers')->where('square_id', $squareId)->select('id'));
            $ledger->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
        }
        $refunded = round((float) $refunds->sum('amount'), 2);
        $totals = $ledger->select('type')->selectRaw('SUM(amount) AS total')->groupBy('type')->pluck('total', 'type');
        return [
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
