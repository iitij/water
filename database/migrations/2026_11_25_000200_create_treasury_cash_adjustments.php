<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_cash_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('treasury_cash_count_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_cash_count_review_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('classification', 30);
            $table->string('reference', 150)->unique();
            $table->string('reason', 500);
            $table->text('evidence');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->date('adjusted_on');
            $table->timestamps();
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->foreignId('treasury_cash_adjustment_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Cash adjustment evidence cannot be removed by migration rollback.');
    }
};
