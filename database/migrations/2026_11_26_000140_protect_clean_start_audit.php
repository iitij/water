<?php

use App\Services\FinancialCleanStartAuditProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialCleanStartAuditProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Clean-start evidence protection requires reviewed recovery.');
    }
};
