<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_bank_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 255);
            $table->string('payment_reference', 255);
            $table->string('payment_method', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->date('cleared_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('treasury_bank_collection_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Bank collection evidence cannot be removed by migration rollback.');
    }
};
