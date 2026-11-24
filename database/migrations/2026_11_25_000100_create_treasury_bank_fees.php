<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_bank_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('charged_on');
            $table->string('reference', 150)->unique();
            $table->string('reason', 500);
            $table->text('evidence');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('treasury_bank_fee_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Bank fee evidence cannot be removed by rollback.');
    }
};
