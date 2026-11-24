<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command('accounting:protect-allocations {--install : Install optional database triggers}', function () {
    $protection = app(\App\Services\AllocationHistoryProtection::class);
    if ($this->option('install')) {
        try {
            $protection->install();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->error('Database rejected trigger installation. Existing protections are retained. No server settings were changed.');
            return 1;
        }
    }
    if (! $protection->active()) {
        $this->warn('Database triggers are not fully active. Application-level integrity checks remain enabled.');
        return 1;
    }
    $this->info('All four allocation history triggers are present.');
    return 0;
})->purpose('Check or explicitly install optional allocation history database protection');

// Time alone can make an unpaid invoice overdue.
\Illuminate\Support\Facades\Schedule::command('accounting:refresh-invoice-statuses')
    ->dailyAt('00:00')->withoutOverlapping();
