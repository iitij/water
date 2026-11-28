<?php

use App\Services\HeldCashDestinationProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(HeldCashDestinationProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Held cash destination protection requires reviewed recovery.');
    }
};
