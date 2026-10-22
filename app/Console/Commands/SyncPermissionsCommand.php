<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'app:sync-permissions';
    protected $description = 'Sync permissions for reader and collector roles';

    public function handle()
    {

        $reader = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'reader', 'guard_name' => 'web']);
        $reader->syncPermissions([
            'ViewAny:Reading', 'View:Reading', 'Create:Reading', 'Update:Reading',
            'ViewAny:Meter', 'View:Meter', 'ViewAny:User', 'View:User'
        ]);
        $this->info('Reader permissions synced.');

        $collector = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        $collector->syncPermissions([
            'ViewAny:Payment', 'View:Payment', 'Create:Payment', 'Update:Payment',
            'ViewAny:Invoice', 'View:Invoice',
            'ViewAny:Reading', 'View:Reading',
            'ViewAny:User', 'View:User',
        ]);
        $this->info('Collector permissions synced.');
    }
}
