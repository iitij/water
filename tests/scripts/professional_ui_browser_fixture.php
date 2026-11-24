<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== getenv('DB_DATABASE')) {
    throw new RuntimeException('Only an isolated test SQLite database is permitted');
}
if (!str_starts_with((string) getenv('DB_DATABASE'), (string) getenv('RUNNER_TEMP').'/')) {
    throw new RuntimeException('Database must be private to the CI runner');
}

$panel = \Filament\Facades\Filament::getPanel('admin');
\Filament\Facades\Filament::setCurrentPanel($panel);
$password = bin2hex(random_bytes(24));
$admin = \App\Models\User::factory()->create([
    'name'=>'UI Test Administrator',
    'email'=>'ui-test@example.test',
    'password'=>\Illuminate\Support\Facades\Hash::make($password),
    'role'=>'admin',
]);
$role = \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
$role->syncPermissions(\Spatie\Permission\Models\Permission::all());
$admin->assignRole($role);
auth()->setUser($admin);

$square = \App\Models\Square::create(['name'=>'مربع اختبار الواجهات']);
$subscriber = \App\Models\Subscriber::create(['name'=>'مشترك تجريبي', 'status'=>'active', 'square_id'=>$square->id]);
$meter = \App\Models\Meter::create(['subscriber_id'=>$subscriber->id, 'unique_code'=>'UI-BROWSER-METER']);
$reading = \App\Models\Reading::create([
    'meter_id'=>$meter->id, 'reading_date'=>'2026-11-03', 'previous_reading'=>0, 'current_reading'=>12,
    'period_start'=>'2026-11-01', 'period_end'=>'2026-11-03',
]);
$invoice = \App\Models\Invoice::create([
    'subscriber_id'=>$subscriber->id, 'meter_id'=>$meter->id, 'bill_number'=>'INV-UI-BROWSER',
    'bill_date'=>'2026-11-03', 'total_amount'=>1200, 'status'=>'issued',
]);
$payment = \App\Models\Payment::create([
    'subscriber_id'=>$subscriber->id, 'invoice_id'=>$invoice->id, 'receipt_number'=>'REC-UI-BROWSER',
    'amount'=>100, 'payment_date'=>'2026-11-03', 'payment_method'=>'cash',
]);
$tariff = \App\Models\Tariff::create(['name'=>'تعرفة تجريبية', 'unit_price'=>100, 'is_active'=>true]);
$testRole = \Spatie\Permission\Models\Role::findOrCreate('ui-browser-role', 'web');
$records = collect([$square,$subscriber,$meter,$reading,$invoice,$payment,$tariff,$testRole,\App\Models\User::factory()->create()])->keyBy(fn ($record) => $record::class);

$routes = ['/admin'];
foreach ([
    \App\Filament\Pages\EditProfilePage::class,
    \App\Filament\Pages\ExportDataPage::class,
    \App\Filament\Pages\InvoiceSettings::class,
    \App\Filament\Pages\ReceiptSettings::class,
    \App\Filament\Pages\MobileDeveloperSettings::class,
    \App\Filament\Pages\ReadingSettings::class,
    \App\Filament\Pages\WhatsAppSettings::class,
] as $page) {
    $routes[] = parse_url($page::getUrl(), PHP_URL_PATH);
}
foreach ($panel->getResources() as $resource) {
    foreach ($resource::getPages() as $name=>$registration) {
        $parameters = [];
        if (in_array($name,['edit','view'],true)) {
            $record = $records->get($resource::getModel());
            if (!$record) throw new RuntimeException('Missing test fixture for '.$resource);
            $parameters['record']=$record;
        }
        $routes[]=parse_url($resource::getUrl($name,$parameters),PHP_URL_PATH);
    }
}
$routes=array_values(array_unique($routes));
if (count($routes) < 39) throw new RuntimeException('Unexpectedly few registered routes');
$path=getenv('RUNNER_TEMP').'/ui-browser-fixture.json';
file_put_contents($path,json_encode(['email'=>$admin->email,'password'=>$password,'routes'=>$routes],JSON_THROW_ON_ERROR));
chmod($path,0600);
echo 'BROWSER_FIXTURE_ROUTES: '.count($routes).PHP_EOL;
