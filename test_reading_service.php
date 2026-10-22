<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\ReadingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Mock the settings and run the test
DB::table('settings')->updateOrInsert(['key' => 'reading_period'], ['value' => 'monthly']);
DB::table('settings')->updateOrInsert(['key' => 'allowed_days_before_period_end'], ['value' => '4']);
DB::table('settings')->updateOrInsert(['key' => 'allowed_days_after_period_end'], ['value' => '4']);

// Let's create a dummy subscriber and meter
$subId = DB::table('subscribers')->insertGetId([
    'name' => 'Test Sub',
    'square_id' => 1,
    'subscription_start_date' => '2026-05-01',
    'status' => 'active'
]);

$meterId = DB::table('meters')->insertGetId([
    'subscriber_id' => $subId,
    'unique_code' => 'TESTMETER123'
]);

$service = new ReadingService();

echo "Scenario 1: First reading, entered on 31/05/2026\n";
$res1 = $service->analyzeReadingDate($meterId, Carbon::parse('2026-05-31'));
print_r($res1);

// Now simulate we have this reading
DB::table('readings')->insert([
    'meter_id' => $meterId,
    'reading_date' => $res1['readingDate'],
    'actual_reading_date' => $res1['actualReadingDate'],
    'current_reading' => 10,
    'consumption_units' => 10,
    'is_invoiced' => false,
    'created_by' => 1
]);

echo "\nScenario 2: Second reading, entered early on 09/06/2026\n";
$res2 = $service->analyzeReadingDate($meterId, Carbon::parse('2026-06-09'));
print_r($res2);

echo "\nScenario 3: Second reading, entered in window on 01/07/2026\n";
$res3 = $service->analyzeReadingDate($meterId, Carbon::parse('2026-07-01'));
print_r($res3);

echo "\nScenario 4: Second reading, entered late on 05/07/2026\n";
$res4 = $service->analyzeReadingDate($meterId, Carbon::parse('2026-07-05'));
print_r($res4);

// Simulate late reading saved
DB::table('readings')->insert([
    'meter_id' => $meterId,
    'reading_date' => $res4['readingDate'],
    'actual_reading_date' => $res4['actualReadingDate'],
    'current_reading' => 20,
    'consumption_units' => 10,
    'is_invoiced' => false,
    'created_by' => 1
]);

echo "\nScenario 5: Third reading after a late reading, entered on 31/07/2026\n";
$res5 = $service->analyzeReadingDate($meterId, Carbon::parse('2026-07-31'));
print_r($res5);

// cleanup
DB::table('readings')->where('meter_id', $meterId)->delete();
DB::table('meters')->where('id', $meterId)->delete();
DB::table('subscribers')->where('id', $subId)->delete();
