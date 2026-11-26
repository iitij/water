<?php

use App\Services\ReceiptVoidProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ReceiptVoidProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Receipt void protection requires independently reviewed recovery.');
    }
};
