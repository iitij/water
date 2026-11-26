<?php

use App\Services\ReceiptVoidRequestProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ReceiptVoidRequestProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Receipt request protection requires independently reviewed recovery.');
    }
};
