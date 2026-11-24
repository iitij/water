<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('closed_cash_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('duplicate_of_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->text('evidence');
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        throw new \RuntimeException('Closed cash correction evidence cannot be deleted by rollback.');
    }
};
