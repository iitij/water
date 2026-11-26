<?php

use App\Services\FinancialCloseJournalProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialCloseJournalProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Financial close evidence requires independently reviewed recovery.');
    }
};
