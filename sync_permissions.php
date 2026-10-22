<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// Ensure all shield permissions exist
\Illuminate\Support\Facades\Artisan::call('shield:generate', ['--all' => true]);

$reader = Role::findByName('reader');
if ($reader) {
    $reader->syncPermissions([
        'view_any_reading', 'view_reading', 'create_reading', 'update_reading',
        'view_any_user', 'view_user',
        'view_any_tenant', 'view_tenant'
    ]);
}

$collector = Role::findByName('collector');
if ($collector) {
    $collector->syncPermissions([
        'view_any_payment', 'view_payment', 'create_payment', 'update_payment',
        'view_any_invoice', 'view_invoice',
        'view_any_user', 'view_user',
        'view_any_reading', 'view_reading',
        'view_any_tenant', 'view_tenant'
    ]);
}

echo "Permissions Synced Successfully!\n";
