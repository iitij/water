<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $pdf = \Mccarlosen\LaravelMpdf\Facades\LaravelMpdf::loadView('pdf.invoice', ['invoice' => App\Models\Invoice::first()]);
    file_put_contents('public/test.pdf', $pdf->output());
    echo 'Size: ' . filesize('public/test.pdf');
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
}
