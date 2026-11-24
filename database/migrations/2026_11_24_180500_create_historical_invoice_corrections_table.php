<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('historical_invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('replacement_invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->decimal('corrected_units', 12, 2);
            $table->decimal('historical_unit_price', 12, 2);
            $table->decimal('corrected_amount', 12, 2);
            $table->text('reason');
            $table->text('evidence');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        throw new \RuntimeException('Historical financial correction evidence cannot be dropped.');
    }
};
