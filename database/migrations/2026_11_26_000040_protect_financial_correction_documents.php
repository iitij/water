<?php

use App\Services\FinancialCorrectionDocumentProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialCorrectionDocumentProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Financial correction document protection requires independently reviewed recovery.');
    }
};
