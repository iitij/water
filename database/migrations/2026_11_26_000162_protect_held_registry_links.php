<?php

use App\Services\HeldRegistryLinkProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(HeldRegistryLinkProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Held registry linkage requires reviewed recovery.');
    }
};
