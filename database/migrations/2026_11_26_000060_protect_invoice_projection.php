<?php

use App\Services\InvoiceProjectionProtection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InvoiceProjectionProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Invoice projection protection requires independently reviewed recovery.');
    }
};
