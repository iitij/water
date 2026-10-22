<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$meter = \App\Models\Meter::where('unique_code', '2025062306')->first();
if ($meter) {
    try {
        echo json_encode(app(\App\Services\ReadingService::class)->calculateReadingPeriod($meter->id));
    } catch (\Exception $e) {
        echo 'Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    }
} else {
    echo 'Meter not found';
}
