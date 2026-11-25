<?php

use Illuminate\Database\Migrations\Migration;
use App\Services\AllocationHistoryProtection;

return new class extends Migration {
    public function up(): void
    {
        // The held-credit candidate depends on immutable allocation history.
        // Do not silently proceed on an engine that cannot install the guards.
        app(AllocationHistoryProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Allocation history protection requires independently reviewed recovery.');
    }
};
