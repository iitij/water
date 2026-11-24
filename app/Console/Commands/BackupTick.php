<?php

namespace App\Console\Commands;

use App\Support\BackupControl;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BackupTick extends Command
{
    protected $signature = 'water:backup-tick {--initialize : Save the default policy without running a backup} {--inspect : Check scheduling without running a backup}';
    protected $description = 'Run a due encrypted backup from the private hosting scheduler';

    public function handle(BackupControl $control): int
    {
        if ($this->option('initialize')) {
            $control->initialize();
            $this->info('BACKUP_POLICY: INITIALIZED');
            return self::SUCCESS;
        }
        if ($this->option('inspect')) {
            $control->policy();
            $this->info('BACKUP_POLICY: VALID');
            return self::SUCCESS;
        }
        $result = $control->tick(function (): bool {
            $runner = config('water-backup.runner');
            if (! is_file($runner) || is_link($runner)) {
                return false;
            }
            $process = new Process(['/bin/bash', $runner], base_path());
            $process->setTimeout(1230);
            $process->disableOutput();
            return $process->run() === 0;
        });
        $this->info('BACKUP_TICK: '.$result);
        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
