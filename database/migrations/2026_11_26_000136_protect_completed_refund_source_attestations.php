<?php

use App\Services\CompletedCashRefundSourceProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompletedCashRefundSourceProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Refund source evidence requires independently reviewed recovery.');
    }
};
