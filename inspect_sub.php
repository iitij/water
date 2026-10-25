<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sub = App\Models\Subscriber::where('name', 'like', '%الذبحاني%')->first();
if (!$sub) {
    echo "Subscriber not found by name.\n";
    $first = App\Models\Subscriber::first();
    if ($first) {
        echo "First subscriber: " . $first->id . " " . $first->name . "\n";
    }
    exit;
}

echo "Found subscriber: ID = {$sub->id}, Name = {$sub->name}\n";

$controller = app(App\Http\Controllers\Api\SubscriberController::class);
$user = App\Models\User::first();
$req = Illuminate\Http\Request::create('/api/subscribers/' . $sub->id, 'GET');
$req->setUserResolver(fn() => $user);
app()->instance('request', $req);
Illuminate\Support\Facades\Request::swap($req);

$res = $controller->show($sub->id);
$json = $res->getContent();
file_put_contents('scratch_subscriber_360.json', $json);
echo "Saved JSON to scratch_subscriber_360.json, length = " . strlen($json) . "\n";
