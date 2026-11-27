<?php

use App\Services\FinancialSourceInsertProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialSourceInsertProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Financial allocation cutoff protection requires reviewed recovery.');
    }
};
