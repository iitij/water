<?php

use App\Services\CompletedCashRefundRequestProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundRequestProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Completed cash refund request evidence requires reviewed recovery.');
    }
};
