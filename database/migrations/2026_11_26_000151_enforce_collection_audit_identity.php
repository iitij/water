<?php

use App\Services\CollectionEvidenceAuditProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CollectionEvidenceAuditProtection::class)->installIdentity();
    }

    public function down(): void
    {
        throw new \LogicException('Collection audit identity protection requires reviewed recovery.');
    }
};
