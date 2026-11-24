<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('from_subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->foreignId('to_subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->text('evidence');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        throw new \RuntimeException('Financial transfer evidence cannot be dropped.');
    }
};
