<?php

use App\Services\CustodyShortageDecisionProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CustodyShortageDecisionProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Custody shortage decision protection requires reviewed recovery.');
    }
};
