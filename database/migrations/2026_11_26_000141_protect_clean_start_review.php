<?php

use App\Services\FinancialCleanStartAuditProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new FinancialCleanStartAuditProtection(true))->install();
    }

    public function down(): void
    {
        throw new \LogicException('Clean-start review evidence requires reviewed recovery.');
    }
};
