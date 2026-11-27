<?php

use App\Services\CustodyShortageRecoveryProposalProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CustodyShortageRecoveryProposalProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Custody shortage recovery proposal protection requires reviewed recovery.');
    }
};
