<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Services\HeldCashReleaseDocumentProtection;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('held_cash_release_proposals', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary();
            $table->foreignId('credit_id')->constrained('treasury_held_cash_credits')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('payment_allocation_revisions')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->char('allocation_hash', 64);
            $table->bigInteger('amount_minor');
            $table->bigInteger('applied_minor');
            $table->bigInteger('recorded_balance_minor');
            $table->bigInteger('pending_refunds_minor');
            $table->bigInteger('protected_minor');
            $table->date('effective_on');
            $table->string('reference', 150)->unique();
            $table->text('evidence');
        });
        Schema::create('held_cash_release_withdrawals', function (Blueprint $table) {
            $table->uuid('proposal_uuid')->primary();
            $table->foreign('proposal_uuid', 'release_withdrawal_proposal_fk')->references('operation_uuid')->on('held_cash_release_proposals')->restrictOnDelete();
            $table->foreignId('withdrawn_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('payment_allocation_revisions')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->char('allocation_hash', 64);
            $table->bigInteger('applied_minor');
            $table->date('effective_on');
            $table->text('evidence');
        });
        app(HeldCashReleaseDocumentProtection::class)->install();
    }

    public function down(): void
    {
        throw new \LogicException('Release proposal history requires independently reviewed recovery.');
    }
};
