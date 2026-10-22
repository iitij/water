<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = App\Models\User::find(1);
echo "Name: {$user->name}\n";
echo "Avatar raw: " . var_export($user->avatar, true) . "\n";
echo "Avatar URL: " . ($user->avatar ? Illuminate\Support\Facades\Storage::url($user->avatar) : 'null') . "\n";
echo "Storage disk: " . config('filesystems.default') . "\n";
