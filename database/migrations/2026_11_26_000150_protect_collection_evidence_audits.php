<?php

use App\Services\CollectionEvidenceAuditProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CollectionEvidenceAuditProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Collection evidence audit protection requires reviewed recovery.');
    }
};
