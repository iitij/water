<?php

use App\Services\CompletedCashRefundResolutionApprovalProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundResolutionApprovalProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Refund incident resolution approval requires independently reviewed recovery.');
    }
};
