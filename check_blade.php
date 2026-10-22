<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $html = view('pdf.invoice', ['invoice' => App\Models\Invoice::first()])->render();
    echo 'Length: ' . strlen($html);
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
}
