<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Spatie\Permission\Models\Role;
use App\Models\User;

$rolesToCreate = ['super_admin', 'admin', 'collector', 'meter_reader'];
foreach ($rolesToCreate as $r) {
    Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
}

foreach (User::all() as $u) {
    if ($u->role) {
        $r = $u->role === 'admin' ? 'super_admin' : $u->role;
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        $u->assignRole($r);
    }
}
echo "Done\n";
