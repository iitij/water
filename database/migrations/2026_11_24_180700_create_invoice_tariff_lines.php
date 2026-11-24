<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_tariff_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('reading_id')->constrained()->restrictOnDelete();
            $table->foreignId('tariff_id')->constrained()->restrictOnDelete();
            $table->string('tariff_name');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('units', 12, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->unique(['invoice_id', 'reading_id']);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Historical pricing evidence cannot be dropped.');
    }
};
