<?php

use App\Services\SessionTransferDestinationProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SessionTransferDestinationProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Session and transfer destination protection requires reviewed recovery.');
    }
};
