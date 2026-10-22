<?php
require __DIR__.'/bootstrap/app.php';
$app = app();
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inv = \App\Models\Invoice::with('subscriber')->latest()->first();
echo 'Sub: ' . $inv->subscriber->name . "\n";
echo 'Fee: ' . $inv->consumption_fee . "\n";

$reading = \App\Models\Reading::whereHas('meter', function($q) use ($inv) {
    $q->where('subscriber_id', $inv->subscriber_id);
})->latest()->first();

echo 'Units: ' . $reading->consumption_units . "\n";
echo 'Calculated Unit Price: ' . ($inv->consumption_fee / $reading->consumption_units) . "\n";
