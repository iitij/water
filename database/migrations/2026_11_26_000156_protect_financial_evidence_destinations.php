<?php

use App\Services\FinancialEvidenceDestinationProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialEvidenceDestinationProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Financial evidence destination protection requires reviewed recovery.');
    }
};
