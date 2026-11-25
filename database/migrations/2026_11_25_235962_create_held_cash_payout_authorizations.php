<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // No silent adoption of historical disbursements without pre-authorization.
        if (DB::table('treasury_held_cash_payouts')->exists()) {
            throw new \LogicException('Existing held payouts require independent migration review.');
        }
        Schema::create('treasury_held_cash_payout_authorizations', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->uuid('assignment_uuid');
            $table->foreign('assignment_uuid', 'held_auth_assignment_fk')->references('operation_uuid')->on('treasury_held_cash_assignments')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('authorized_at_utc', 32);
            $table->string('reference', 150)->unique();
            $table->text('evidence');
        });
        Schema::create('treasury_held_cash_payout_cancellations', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreign('operation_uuid', 'held_cancel_auth_fk')->references('operation_uuid')->on('treasury_held_cash_payout_authorizations')->restrictOnDelete();
            $table->foreignId('cancelled_by')->constrained('users')->restrictOnDelete();
            $table->date('effective_on');
            $table->text('evidence');
        });
    }

    public function down(): void
    {
        throw new \LogicException('Payout authorization history requires reviewed recovery.');
    }
};
