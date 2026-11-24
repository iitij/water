<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * The former destructive rebuild cannot reconstruct reversals, refunds,
 * transfers or historical provenance. Keep the old entry point read-only.
 */
class MigrateToLedger extends Command
{
    protected $signature = 'ledger:migrate';
    protected $description = 'Read-only audit of original invoice and payment postings; never rebuild the ledger';

    public function handle(): int
    {
        $issues = 0;
        foreach ([Invoice::class => 'invoice', Payment::class => 'payment'] as $model => $type) {
            $model::query()->chunkById(200, function ($documents) use ($type, &$issues) {
                foreach ($documents as $doc) {
                    $rows = Transaction::withTrashed()->where('reference_type', get_class($doc))
                        ->where('reference_id', $doc->id)->where('type', $type)->get();
                    if ($type === 'invoice' && $doc->status === 'draft') {
                        $valid = $rows->isEmpty();
                    } else {
                        $expected = (int) round((float) ($type === 'invoice' ? -$doc->total_amount : $doc->amount) * 100);
                        $valid = $rows->count() === 1 && ! $rows->first()->trashed()
                            && (int) $rows->first()->subscriber_id === (int) $doc->subscriber_id
                            && (int) round((float) $rows->first()->amount * 100) === $expected
                            && (int) ($rows->first()->meter_id ?? 0) === (int) ($doc->meter_id ?? 0)
                            && (int) ($rows->first()->retired_meter_id ?? 0) === (int) ($doc->retired_meter_id ?? 0);
                    }
                    if (! $valid) {
                        $issues++;
                        $this->warn($type.' #'.$doc->id.' has a missing, duplicate or mismatched original posting.');
                    }
                }
            });
        }
        $this->info('Read-only original posting audit; issues: '.$issues.'. No data changed.');
        return $issues === 0 ? self::SUCCESS : self::FAILURE;
    }
}
