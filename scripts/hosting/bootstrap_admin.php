<?php

declare(strict_types=1);

// One-time administrator provisioning. Credentials arrive only via STDIN.
$stage = 'input';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $email = $input['email'] ?? null;
    $password = $input['password'] ?? null;
    if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password) || strlen($password) < 16 || strlen($password) > 72 ||
        preg_match('/[\x00-\x1f\x7f]/', $password)) {
        throw new RuntimeException('invalid-input');
    }
    unset($input);
    $stage = 'bootstrap';
    $project = rtrim((string) getenv('HOME'), '/') . '/repositories/water';
    if (!chdir($project)) throw new RuntimeException('missing-project');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $config = $app->make('config');
    $db = $app->make('db')->connection();
    if ($config->get('app.env') !== 'production' || $config->get('app.debug') !== false ||
        $config->get('database.default') !== 'mysql' ||
        $db->getConfig('database') !== 'alweufti_water' ||
        $db->getConfig('username') !== 'alweufti_water' ||
        $db->getConfig('host') !== 'localhost' ||
        $db->getConfig('engine') !== 'InnoDB' ||
        $db->selectOne('SELECT DATABASE() AS selected_db')->selected_db !== 'alweufti_water') {
        throw new RuntimeException('wrong-configuration');
    }
    $stage = 'schema-guard';
    $actual = $db->table('migrations')->pluck('migration')->sort()->values()->all();
    $expected = array_map(static fn ($file) => basename($file, '.php'), glob('database/migrations/*.php'));
    sort($expected);
    if (count($expected) !== 51 || $actual !== $expected) throw new RuntimeException('migration-mismatch');
    $engineCheck = $db->selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ? AND ENGINE <> ?',
        ['BASE TABLE','InnoDB']
    );
    if ((int) $engineCheck->n !== 0) throw new RuntimeException('engine-mismatch');
    foreach (['users','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $table) {
        if ($db->table($table)->exists()) throw new RuntimeException('existing-account-or-permission-data');
    }
    if (!isset($kernel->all()['shield:generate'])) throw new RuntimeException('shield-command-missing');
    echo "ADMIN_PREFLIGHT: PASSED\n";
    $db->transaction(function () use ($app, $db, $kernel, $email, $password, &$stage): void {
        $stage = 'permission-generation';
        $result = $kernel->call('shield:generate', [
            '--all'=>true, '--panel'=>'admin', '--option'=>'permissions', '--no-interaction'=>true,
        ]);
        if ($result !== 0) throw new RuntimeException('shield-failed');
        $role = \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $stage = 'custom-permissions';
        (new \Database\Seeders\CashSessionPermissionsSeeder())->run();
        $permissions = \Spatie\Permission\Models\Permission::query()->where('guard_name','web')->get();
        if ($permissions->isEmpty()) throw new RuntimeException('no-permissions');
        foreach (['ViewAny:User','ViewAny:Role','cash_sessions.manage','cash_sessions.approve','audit.view'] as $required) {
            if (!$permissions->contains('name', $required)) throw new RuntimeException('missing-required-permission');
        }
        $role->syncPermissions($permissions);
        $stage = 'create-administrator';
        if ($db->table('users')->exists()) throw new RuntimeException('existing-account');
        $user = \App\Models\User::create([
            'name'=>'مدير النظام',
            'email'=>$email,
            'password'=>\Illuminate\Support\Facades\Hash::make($password),
            'role'=>'admin',
        ]);
        $user->assignRole($role);
        $app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user->refresh();
        $stage = 'verify-administrator';
        if (!$user->hasRole('super_admin') || !$user->isAdmin() ||
            !$user->can('ViewAny:User') || !$user->can('ViewAny:Role') ||
            !$user->can('cash_sessions.approve') ||
            !$user->can('audit.view') ||
            !\Illuminate\Support\Facades\Hash::check($password, $user->password) ||
            !$user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) ||
            $db->table('users')->count() !== 1) {
            throw new RuntimeException('verification-failed');
        }
        echo "ADMIN_ROLE: super_admin\n";
        echo "WEB_PERMISSIONS: ", $permissions->count(), PHP_EOL;
        echo "ADMIN_ACCOUNTS: 1\n";
    });
    unset($password);
    $app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    echo "ADMIN_BOOTSTRAP: PASSED\n";
    echo "DEMO_DATA: NOT_SEEDED\n";
} catch (Throwable $e) {
    // Never print raw exception messages or SQL bindings that may contain credentials.
    fwrite(STDERR, "ADMIN_BOOTSTRAP: FAILED AT " . $stage . "; inspect current state before retrying.\n");
    exit(1);
}
