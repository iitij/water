<?php

namespace App\Console\Commands;

use App\Services\LedgerProtection;
use Illuminate\Console\Command;

final class ProtectLedger extends Command
{
    protected $signature = 'ledger:protect {--install : Install missing database triggers} {--check : Verify required guards exist}';
    protected $description = 'Install or verify database-level ledger protection (no financial data changes)';

    public function handle(LedgerProtection $protection): int
    {
        try {
            if ($this->option('install')) {
                $protection->install();
            }
            if (! $protection->active()) {
                $this->error('Ledger database protection incomplete; production acceptance blocked.');
                return self::FAILURE;
            }
            $this->info('Required ledger database guards are present.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Ledger protection could not be verified: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
