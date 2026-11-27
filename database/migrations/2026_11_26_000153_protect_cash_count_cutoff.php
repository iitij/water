<?php

use App\Services\CashCountPeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CashCountPeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Cash count evidence protection requires reviewed recovery.');
    }
};
