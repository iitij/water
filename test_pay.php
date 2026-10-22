<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $req = new \Illuminate\Http\Request();
    $req->merge(['amount'=>1, 'payment_method'=>'cash', 'reference_number'=>'']);
    $req->setUserResolver(function() { return App\Models\User::first(); });
    $res = app(App\Http\Controllers\Api\InvoiceController::class)->pay($req, 28);
    echo "Status: " . $res->getStatusCode() . "\n";
    echo "Content: " . $res->getContent() . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
