<?php

use App\Services\DatedFinancialSourcePeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(DatedFinancialSourcePeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Dated source destination protection requires reviewed recovery.');
    }
};
