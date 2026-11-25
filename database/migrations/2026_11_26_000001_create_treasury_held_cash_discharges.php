<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_held_cash_discharges', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreign('operation_uuid')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete();
            $table->foreignId('liability_id')->constrained('treasury_held_cash_liabilities')->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('method', 30);
            $table->bigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('reference', 150)->unique('held_cash_discharge_ref_unique');
            $table->text('evidence');
            $table->foreignId('treasury_posting_id')->nullable()->unique('held_cash_discharge_posting_unique')
                ->constrained('treasury_postings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('treasury_held_cash_discharges')->exists()) {
            throw new \LogicException('Held cash discharge evidence requires reviewed recovery.');
        }
        Schema::dropIfExists('treasury_held_cash_discharges');
    }
};
