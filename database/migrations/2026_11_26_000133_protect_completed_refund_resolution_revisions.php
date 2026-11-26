<?php

use App\Services\CompletedCashRefundResolutionRevisionProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundResolutionRevisionProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Refund incident resolution proposal requires independently reviewed recovery.');
    }
};
