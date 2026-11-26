<?php

use App\Services\FinancialPostingRegistryProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialPostingRegistryProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Posting registry evidence requires independently reviewed recovery.');
    }
};
