<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshInvoiceStatuses extends Command
{
    protected $signature = 'accounting:refresh-invoice-statuses';

    protected $description = 'Refresh invoice payment and overdue statuses without replaying allocations';

    public function handle(): int
    {
        $changed = 0;
        Invoice::query()->whereNotIn('status', ['draft', 'cancelled'])
            ->select(['id', 'subscriber_id'])->chunkById(200, function ($rows) use (&$changed): void {
                foreach ($rows as $row) {
                    $changed += DB::transaction(function () use ($row): int {
                        // Same lock order as invoice issuance and payment allocation.
                        Subscriber::withTrashed()->lockForUpdate()->findOrFail($row->subscriber_id);
                        $invoice = Invoice::query()->lockForUpdate()->find($row->id);
                        if (! $invoice) {
                            return 0;
                        }
                        $before = $invoice->status;
                        $invoice->updateStatus();

                        return (int) ($before !== $invoice->status);
                    }, 3);
                }
            });

        $this->info("Updated {$changed} invoice statuses.");

        return self::SUCCESS;
    }
}
