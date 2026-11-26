<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RuntimeDatabasePrivileges;
use App\Services\LedgerProtection;
use App\Services\AllocationHistoryProtection;
use App\Services\HeldCashDocumentProtection;
use App\Services\HeldCashReleaseDocumentProtection;
use App\Services\FinancialCorrectionDocumentProtection;
use App\Services\RegisteredDocumentProtection;
use App\Services\InvoiceProjectionProtection;
use App\Services\ReceiptVoidProtection;
use App\Services\ReceiptVoidRequestProtection;
use Illuminate\Console\Command;

final class FinancialDatabasePreflight extends Command
{
    protected $signature = 'financial:database-preflight
        {--runtime : Verify grants of the actual runtime write connection}
        {--guards : Verify database protection using the separate deployment account}';
    protected $description = 'Read-only prospective financial database deployment checks';

    public function handle(RuntimeDatabasePrivileges $privileges): int
    {
        if ($this->option('runtime') === $this->option('guards')) {
            $this->error('Select exactly one of --runtime or --guards.');
            return self::FAILURE;
        }
        try {
            if ($this->option('runtime')) {
                $result = $privileges->inspect();
                foreach ($result['issues'] as $issue) $this->error($issue);
                if (! $result['accepted']) return self::FAILURE;
                $this->info('Runtime grant allowlist accepted. DML coverage and application authorization are not certified.');
                return self::SUCCESS;
            }
            $checks = [
                'ledger' => fn () => app(LedgerProtection::class)->active(),
                'allocations' => fn () => app(AllocationHistoryProtection::class)->active(),
                'held_documents' => fn () => app(HeldCashDocumentProtection::class)->active(),
                'held_release' => fn () => app(HeldCashReleaseDocumentProtection::class)->active(),
                'held_release_journal' => fn () => app(HeldCashReleaseDocumentProtection::class)->journalActive(),
                'held_reinstatement' => fn () => app(HeldCashReleaseDocumentProtection::class)->reinstatementActive(),
                'correction_documents' => fn () => app(FinancialCorrectionDocumentProtection::class)->active(),
                'registered_documents' => fn () => app(RegisteredDocumentProtection::class)->active(),
                'invoice_projection' => fn () => app(InvoiceProjectionProtection::class)->active(),
                'receipt_void' => fn () => app(ReceiptVoidProtection::class)->active(),
                'receipt_request' => fn () => app(ReceiptVoidRequestProtection::class)->active(),
            ];
            $ok = true;
            foreach ($checks as $name => $check) {
                try {
                    if ($check()) {
                        $this->info($name.': present');
                        continue;
                    }
                } catch (\Throwable) {
                    // Never print connection details or raw server errors.
                }
                $this->error($name.': missing or unverifiable');
                $ok = false;
            }
            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Database preflight could not be verified.');
            return self::FAILURE;
        }
    }
}
