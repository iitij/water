<?php

namespace App\Console\Commands;

use App\Support\BackupControl;
use Illuminate\Console\Command;

class BackupDevice extends Command
{
    protected $signature = 'water:backup-device {operation : allowed or ack} {archive?} {digest?}';
    protected $description = 'Check the enrolled backup reader policy or record a verified device receipt';

    public function handle(BackupControl $control): int
    {
        return match ($this->argument('operation')) {
            'allowed' => $control->deviceAllowed() ? self::SUCCESS : 3,
            'ack' => $this->ack($control),
            default => self::INVALID,
        };
    }

    private function ack(BackupControl $control): int
    {
        try {
            $control->acknowledge((string) $this->argument('archive'), (string) $this->argument('digest'));
            $this->info('DEVICE_RECEIPT: ACCEPTED');
            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error('DEVICE_RECEIPT: REJECTED');
            return self::FAILURE;
        }
    }
}
