<?php

use App\Services\BankRangeDestinationProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BankRangeDestinationProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Bank range destination protection requires reviewed recovery.');
    }
};
