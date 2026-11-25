<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_held_cash_payouts', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreign('operation_uuid', 'held_payout_operation_fk')->references('operation_uuid')->on('financial_posting_operations')->restrictOnDelete();
            $table->uuid('assignment_uuid');
            $table->foreign('assignment_uuid', 'held_payout_assignment_fk')->references('operation_uuid')->on('treasury_held_cash_assignments')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
        });
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->uuid('held_cash_payout_uuid')->nullable()->unique('held_payout_posting_unique');
            $table->foreign('held_cash_payout_uuid', 'held_payout_posting_fk')->references('operation_uuid')->on('treasury_held_cash_payouts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('treasury_held_cash_payouts')->exists()) {
            throw new \LogicException('Held cash payout evidence requires reviewed recovery.');
        }
        Schema::table('treasury_postings', function (Blueprint $table) {
            $table->dropForeign('held_payout_posting_fk');
            $table->dropUnique('held_payout_posting_unique');
            $table->dropColumn('held_cash_payout_uuid');
        });
        Schema::dropIfExists('treasury_held_cash_payouts');
    }
};
