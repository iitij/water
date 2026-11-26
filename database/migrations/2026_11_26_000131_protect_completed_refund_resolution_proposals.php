<?php

use App\Services\CompletedCashRefundResolutionProposalProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundResolutionProposalProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Refund incident resolution proposal requires independently reviewed recovery.');
    }
};
