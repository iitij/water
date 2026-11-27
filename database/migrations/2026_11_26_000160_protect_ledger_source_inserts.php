<?php

use App\Services\LedgerSourceInsertProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LedgerSourceInsertProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Ledger source protection requires reviewed recovery.');
    }
};
