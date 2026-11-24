<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // MySQL shared hosting may deny CREATE TRIGGER when binlogging is
        // enabled. The optional explicit command installs it if permitted.
        if (DB::getDriverName() === 'sqlite') {
            app(\App\Services\AllocationHistoryProtection::class)->install();
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Allocation history protection cannot be removed by rollback.');
    }
};
