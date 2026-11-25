<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Transaction;
use App\Support\AccountMoney;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;
use Illuminate\Support\Facades\DB;

/** Read-only accounting projections. Never mutate posted documents. */
final class FinancialReportingService
{
    /** Current balances, independent of the invoice issue-date cohort. */
    public function accountBalances(?int $squareId = null): array
    {
        $entries = Transaction::query()->join('subscribers', 'subscribers.id', '=', 'transactions.subscriber_id')
            ->whereNull('subscribers.deleted_at')
            ->when($squareId !== null, fn ($q) => $q->where('subscribers.square_id', $squareId))
            ->select('transactions.*')->orderBy('transactions.subscriber_id')->orderBy('transactions.id');
        $debt = $credit = $balance = 0;
        $account = null;
        $accumulate = static function (int $net) use (&$debt, &$credit): void {
            if ($net < 0) {
                $debt = SignedMinor::add($debt, SignedMinor::negate($net));
            } else {
                $credit = SignedMinor::add($credit, $net);
            }
        };
        foreach ($entries->cursor() as $entry) {
            $id = (int) $entry->subscriber_id;
            if ($account !== null && $id !== $account) {
                $accumulate($balance);
                $balance = 0;
            }
            $account = $id;
            $balance = SignedMinor::add($balance, AccountMoney::stored($entry));
        }
        $accumulate($balance);

        return [
            'debt' => MoneyBoundary::numericMajorOutput($debt),
            'credit' => MoneyBoundary::numericMajorOutput($credit),
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

    private function positiveDocument(\Illuminate\Database\Eloquent\Model $row): int
    {
        $minor = AccountMoney::stored($row);
        if ($minor <= 0) {
            throw new \LogicException('Financial receipt or refund must be positive.');
        }
        return $minor;
    }

    public function grossReceiptMinor(string $from, string $to, ?int $squareId = null): int
    {
        $sum = 0;
        foreach ($this->receipts($from, $to, $squareId)->select('payments.amount')->orderBy('payments.id')->cursor() as $row) {
            $sum = SignedMinor::add($sum, $this->positiveDocument($row));
        }
        return $sum;
    }

    public function grossReceipts(string $from, string $to, ?int $squareId = null): float
    {
        return MoneyBoundary::numericMajorOutput($this->grossReceiptMinor($from, $to, $squareId));
    }

    /** Derive all monetary receipt breakdowns from the same observed source rows. */
    public function receiptBreakdowns(Builder $query): array
    {
        $rows = (clone $query)->leftJoin('subscribers', 'subscribers.id', '=', 'payments.subscriber_id')
            ->leftJoin('squares', 'squares.id', '=', 'subscribers.square_id')
            ->select('payments.amount', 'payments.payment_method', 'squares.id as report_square_id', 'squares.name as report_square_name')
            ->selectRaw('DATE(payments.payment_date) AS report_day')
            ->orderBy('squares.name')->orderBy('squares.id')->orderBy('payments.id');
        $total = 0;
        $days = $methods = $squares = [];
        foreach ($rows->cursor() as $row) {
            $minor = $this->positiveDocument($row);
            $total = SignedMinor::add($total, $minor);
            $day = (string) $row->report_day;
            $method = (string) $row->payment_method;
            $key = $row->report_square_id === null ? 'unassigned' : (string) $row->report_square_id;
            $days[$day] = SignedMinor::add($days[$day] ?? 0, $minor);
            $methods[$method] = SignedMinor::add($methods[$method] ?? 0, $minor);
            $squares[$key] ??= ['key' => $key, 'label' => $row->report_square_name ?? 'بلا مربع', 'minor' => 0, 'count' => 0];
            $squares[$key]['minor'] = SignedMinor::add($squares[$key]['minor'], $minor);
            $squares[$key]['count']++;
        }
        ksort($days, SORT_STRING);
        ksort($methods, SORT_STRING);
        $mysql = $query->getModel()->getConnection()->getDriverName() === 'mysql';
        $revenue = $byMethod = $bySquare = [];
        foreach ($days as $day => $minor) {
            $revenue[] = ['label' => $day, 'value' => MoneyBoundary::numericMajorOutput($minor)];
        }
        foreach ($methods as $method => $minor) {
            // Preserve the previous driver-specific SQL SUM presentation contract.
            $byMethod[] = ['payment_method' => $method, 'total' => $mysql ? SignedMinor::decimal($minor) : MoneyBoundary::numericMajorOutput($minor)];
        }
        foreach ($squares as $square) {
            $bySquare[] = ['key' => $square['key'], 'label' => $square['label'],
                'value' => MoneyBoundary::numericMajorOutput($square['minor']), 'count' => $square['count']];
        }
        return ['minor' => $total, 'revenue' => $revenue, 'methods' => $byMethod, 'squares' => $bySquare];
    }

    /** Own invoice charges only; never clamp an impossible stored allocation. */
    public function invoiceBreakdowns(Builder $query): array
    {
        $billed = $outstanding = $overdue = $count = $paidCount = $overdueCount = 0;
        $statuses = [];
        foreach ((clone $query)->select('id', 'total_amount', 'amount_paid', 'status', 'deleted_at')->orderBy('id')->cursor() as $invoice) {
            $minor = AccountMoney::stored($invoice, 'total_amount');
            $remaining = $invoice->getRemainingMinor();
            $billed = SignedMinor::add($billed, $minor);
            $outstanding = SignedMinor::add($outstanding, $remaining);
            $count++;
            $status = $invoice->status;
            if ($status === 'paid') {
                $paidCount++;
            }
            if ($status === 'overdue') {
                $overdueCount++;
                $overdue = SignedMinor::add($overdue, $remaining);
            }
            $statuses[$status] ??= ['status' => $status, 'minor' => 0, 'total' => 0];
            $statuses[$status]['minor'] = SignedMinor::add($statuses[$status]['minor'], $minor);
            $statuses[$status]['total']++;
        }
        ksort($statuses, SORT_STRING);
        return ['billed' => $billed, 'outstanding' => $outstanding, 'overdue' => $overdue, 'count' => $count,
            'paid_count' => $paidCount, 'overdue_count' => $overdueCount, 'statuses' => array_values($statuses)];
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
        // Compatibility entry point. Do not perform accounting on its presentation float.
        $minor = $this->grossReceiptMinor($from, $to, $squareId);
        if (MoneyBoundary::numericMajorOutput($minor) !== $grossCollection) {
            throw new \LogicException('Gross receipt projection does not match exact source.');
        }
        return $this->movementsFromMinor($from, $to, $squareId, $minor);
    }

    public function movementsFromMinor(string $from, string $to, ?int $squareId, int $grossMinor): array
    {
        $refunds = PaymentRefund::query()->whereDate('disbursed_on', '>=', $from)->whereDate('disbursed_on', '<=', $to);
        $ledger = Transaction::query()->where('created_at', '>=', $from.' 00:00:00')
            ->where('created_at', '<', Carbon::parse($to)->addDay()->toDateString().' 00:00:00');
        if ($squareId !== null) {
            $refunds->whereIn('subscriber_id', DB::table('subscribers')->where('square_id', $squareId)->select('id'));
            $ledger->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
        }
        $refunded = $net = 0;
        foreach ($refunds->select('amount')->orderBy('id')->cursor() as $refund) {
            $refunded = SignedMinor::add($refunded, $this->positiveDocument($refund));
        }
        $totals = [];
        foreach ($ledger->select('amount', 'type')->orderBy('id')->cursor() as $entry) {
            $minor = AccountMoney::stored($entry);
            $net = SignedMinor::add($net, $minor);
            $totals[$entry->type] = SignedMinor::add($totals[$entry->type] ?? 0, $minor);
        }
        return [
            'receipt_basis' => 'currently_valid_receipts_by_collection_date',
            'refund_basis' => 'disbursement_date',
            'ledger_basis' => 'posting_date',
            'gross_valid_receipts' => MoneyBoundary::numericMajorOutput($grossMinor),
            'refunds_disbursed' => MoneyBoundary::numericMajorOutput($refunded),
            'net_receipts_after_refunds' => MoneyBoundary::numericMajorOutput(SignedMinor::subtract($grossMinor, $refunded)),
            'invoice_postings' => MoneyBoundary::numericMajorOutput(SignedMinor::negate($totals['invoice'] ?? 0)),
            'invoice_reversals' => MoneyBoundary::numericMajorOutput($totals['invoice_reversal'] ?? 0),
            'receipt_reversals' => MoneyBoundary::numericMajorOutput(SignedMinor::negate($totals['payment_reversal'] ?? 0)),
            'account_transfers_in' => MoneyBoundary::numericMajorOutput($totals['payment_transfer_in'] ?? 0),
            'held_cash_credits' => MoneyBoundary::numericMajorOutput($totals['held_cash_credit'] ?? 0),
            'ledger_net_movement' => MoneyBoundary::numericMajorOutput($net),
        ];
    }
}
