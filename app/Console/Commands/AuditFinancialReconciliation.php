<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use App\Services\FinancialReconciliationService;
use Illuminate\Console\Command;

final class AuditFinancialReconciliation extends Command
{
    protected $signature = 'ledger:audit {--subscriber= : Audit one active subscriber ID} {--json : Output JSON lines}';
    protected $description = 'Read-only reconciliation of account documents, ledger and allocation snapshots';

    public function handle(FinancialReconciliationService $service): int
    {
        $query = Subscriber::query();
        $id = $this->option('subscriber');
        if ($id !== null) {
            if (! ctype_digit((string) $id) || (int) $id < 1 || ! Subscriber::find((int) $id)) {
                $this->error('Subscriber not found.');
                return self::FAILURE;
            }
            $query->whereKey((int) $id);
        }
        $accounts = 0;
        $failed = 0;
        $query->chunkById(100, function ($subscribers) use ($service, &$accounts, &$failed) {
            foreach ($subscribers as $subscriber) {
                $result = $service->inspect($subscriber);
                $accounts++;
                if (! $result['matches']) $failed++;
                if ($this->option('json')) {
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                } elseif (! $result['matches']) {
                    $this->warn('Subscriber #'.$subscriber->id.': '.$result['issue_count'].' issue(s)');
                    foreach ($result['issues'] as $issue) $this->line(json_encode($issue, JSON_THROW_ON_ERROR));
                }
            }
        });
        if (! $this->option('json')) $this->info("Accounts: $accounts; failed: $failed. No data changed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
