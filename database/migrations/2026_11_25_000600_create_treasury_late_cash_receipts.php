<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_late_cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('late_receipt_review_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('received_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('treasury_late_cash_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Late cash delivery evidence cannot be removed by rollback.');
    }
};
