<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    $count = Subscriber::where('id', '>=', 73)->count();
    
    // Physical delete to trigger SQL ON DELETE CASCADE for all related tables
    Subscriber::where('id', '>=', 73)->forceDelete();

    DB::commit();

    echo "Successfully deleted $count subscribers and all their cascaded data.";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage();
}
