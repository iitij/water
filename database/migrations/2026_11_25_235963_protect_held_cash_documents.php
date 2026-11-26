<?php

use Illuminate\Database\Migrations\Migration;
use App\Services\HeldCashDocumentProtection;

return new class extends Migration {
    public function up(): void
    {
        app(HeldCashDocumentProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Held cash document protection requires independently reviewed recovery.');
    }
};
