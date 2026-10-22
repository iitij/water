<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tables = DB::select('SHOW TABLES');
foreach ($tables as $t) {
    foreach ($t as $k => $v) {
        echo $v . "\n";
    }
}
