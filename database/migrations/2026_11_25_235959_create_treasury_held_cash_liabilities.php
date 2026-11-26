<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_held_cash_liabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_cash_adjustment_id')->unique('held_cash_adjustment_unique');
            $table->foreign('treasury_cash_adjustment_id', 'held_cash_adjustment_fk')->references('id')->on('treasury_cash_adjustments')->restrictOnDelete();
            $table->foreignId('treasury_posting_id')->unique('held_cash_posting_unique');
            $table->foreign('treasury_posting_id', 'held_cash_posting_fk')->references('id')->on('treasury_postings')->restrictOnDelete();
            $table->uuid('operation_uuid')->unique();
            $table->foreign('operation_uuid')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('source_hash', 64);
        });
        Schema::create('treasury_held_cash_assignments', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreign('operation_uuid')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete();
            $table->foreignId('liability_id')->constrained('treasury_held_cash_liabilities')->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
        });
    }

    public function down(): void
    {
        if (DB::table('treasury_held_cash_assignments')->exists()
            || DB::table('treasury_held_cash_liabilities')->exists()) {
            throw new \LogicException('Held cash liability evidence requires reviewed recovery.');
        }
        Schema::dropIfExists('treasury_held_cash_assignments');
        Schema::dropIfExists('treasury_held_cash_liabilities');
    }
};
