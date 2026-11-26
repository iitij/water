<?php

use App\Services\CompletedCashRefundDecisionProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundDecisionProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Completed cash refund decision requires independently reviewed recovery.');
    }
};
