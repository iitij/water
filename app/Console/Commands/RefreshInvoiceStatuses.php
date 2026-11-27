<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Subscriber;
use App\Services\FinancialWriteGate;
use DateTimeZone;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshInvoiceStatuses extends Command
{
    protected $signature = 'accounting:refresh-invoice-statuses';

    protected $description = 'Refresh invoice payment and overdue statuses without replaying allocations';

    public function handle(): int
    {
        $changed = 0;
        // The status is a live projection, not a new posting to the invoice's
        // original period. For a configured scope, serialize it with today's
        // financial writes and close operations even when candidate flags are off.
        $asOf = now()->toDateTimeImmutable();
        $effectiveOn = $asOf->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        try {
            Invoice::query()->whereNotIn('status', ['draft', 'cancelled'])
                ->select(['id', 'subscriber_id'])->chunkById(200, function ($rows) use (&$changed, $asOf, $effectiveOn): void {
                    foreach ($rows as $row) {
                        $work = function () use ($row): int {
                            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                                throw new DomainException('financial_issuance_lock_missing');
                            }
                            Subscriber::withTrashed()->lockForUpdate()->findOrFail($row->subscriber_id);
                            $invoice = Invoice::query()->lockForUpdate()->find($row->id);
                            if (! $invoice || (int) $invoice->subscriber_id !== (int) $row->subscriber_id) {
                                return 0;
                            }
                            $before = $invoice->status;
                            $invoice->updateStatus();
                            return (int) ($before !== $invoice->status);
                        };
                        if (config('financial_candidate.invoices') === true
                            || DB::table('financial_scopes')->where('scope_key', 'water')->exists()) {
                            $changed += app(FinancialWriteGate::class)->withinOpenPeriod('water', $effectiveOn, $asOf, $work);
                        } else {
                            // Existing unconfigured deployments retain the legacy path.
                            // Do not adopt a newly configured scope inside that path.
                            $changed += DB::transaction(function () use ($work): int {
                                if (DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                                    throw new DomainException('financial_scope_configured_retry');
                                }
                                return $work();
                            }, 3);
                        }
                    }
                });
        } catch (DomainException $error) {
            $this->error('Invoice status refresh stopped: '.$error->getMessage());
            return self::FAILURE;
        }

        $this->info("Updated {$changed} invoice statuses.");

        return self::SUCCESS;
    }
}
