<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('disbursement_reference', 150)->unique();
            $table->string('method', 30);
            $table->date('disbursed_on');
            $table->text('reason');
            $table->text('evidence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Financial refund evidence cannot be dropped.');
    }
};
