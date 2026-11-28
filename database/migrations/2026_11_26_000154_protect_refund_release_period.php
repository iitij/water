<?php

use App\Services\RefundReleasePeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RefundReleasePeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Refund and release evidence protection requires reviewed recovery.');
    }
};
