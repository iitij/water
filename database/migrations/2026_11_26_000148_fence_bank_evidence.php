<?php

use App\Services\BankEvidencePeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BankEvidencePeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Bank evidence protection requires reviewed recovery.');
    }
};
