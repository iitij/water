<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Models\Payment::where('invoice_id', 152)->delete();
$inv = App\Models\Invoice::find(152);
$inv->amount_paid = 0;
$inv->status = 'issued';
$inv->save();
echo "Rolled back!\n";
