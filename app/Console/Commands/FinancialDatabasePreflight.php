<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RuntimeDatabasePrivileges;
use Illuminate\Console\Command;

final class FinancialDatabasePreflight extends Command
{
    protected $signature = 'financial:database-preflight
        {--runtime : Verify grants of the actual runtime write connection}
        {--close-control : Verify that the operational identity cannot write close control tables}
        {--guards : Verify database protection directly or through the configured read-only metadata routine}';
    protected $description = 'Read-only prospective financial database deployment checks';

    public function handle(RuntimeDatabasePrivileges $privileges): int
    {
        if (count(array_filter([$this->option('runtime'), $this->option('guards'), $this->option('close-control')])) !== 1) {
            $this->error('Select exactly one of --runtime, --guards or --close-control.');
            return self::FAILURE;
        }
        try {
            if ($this->option('close-control')) {
                $result = $privileges->inspect();
                foreach ($result['close_control_issues'] as $issue) $this->error($issue);
                if (! $result['close_control_write_isolated']) return self::FAILURE;
                $this->info('Close control table writes isolated from operational identity. Writer exclusivity is not certified.');
                return self::SUCCESS;
            }
            if ($this->option('runtime')) {
                $result = $privileges->inspect();
                foreach ($result['issues'] as $issue) $this->error($issue);
                if (! $result['accepted']) return self::FAILURE;
                $this->info('Runtime grant allowlist accepted. DML coverage and application authorization are not certified.');
                return self::SUCCESS;
            }
            $checks = app(\App\Services\FinancialGuardInventory::class)->inspect();
            // Retain existing CLI names for deployment scripts.
            $aliases = ['source_period' => 'financial_source_fences',
                'registry' => 'posting_registry', 'allocation_history' => 'allocations'];
            $ok = true;
            foreach ($checks as $key => $passed) {
                $name = $aliases[$key] ?? $key;
                if ($passed) {
                    $this->info($name.': present');
                    continue;
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
