<?php

use App\Services\CollectionEvidencePeriodProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CollectionEvidencePeriodProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Collection evidence protection requires reviewed recovery.');
    }
};
