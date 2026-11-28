<?php

use App\Services\CustodyShortagePeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CustodyShortagePeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Custody shortage period protection requires reviewed recovery.');
    }
};
