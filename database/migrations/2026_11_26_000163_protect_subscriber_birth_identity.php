<?php

use App\Services\SubscriberBirthIdentityProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SubscriberBirthIdentityProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Subscriber birth identity protection requires reviewed recovery.');
    }
};
