<?php

use App\Services\FinancialTriggerMetadata;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            app(FinancialTriggerMetadata::class)->install();
        }
    }

    public function down(): void
    {
        throw new \LogicException('Financial metadata routine requires independently reviewed recovery.');
    }
};
