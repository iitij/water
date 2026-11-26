<?php

use App\Services\FinancialApprovalAuditProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialApprovalAuditProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Financial approval audit protection requires independently reviewed recovery.');
    }
};
