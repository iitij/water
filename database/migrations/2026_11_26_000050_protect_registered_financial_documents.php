<?php

use App\Services\RegisteredDocumentProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RegisteredDocumentProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Registered financial document protection requires independently reviewed recovery.');
    }
};
