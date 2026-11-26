<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('held_cash_release_journal', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->uuid('proposal_uuid')->nullable()->unique();
            $table->foreign('proposal_uuid', 'release_journal_proposal_fk')->references('operation_uuid')->on('held_cash_release_proposals')->restrictOnDelete();
            $table->foreignId('credit_id')->constrained('treasury_held_cash_credits')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('payment_allocation_revisions')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('amount_minor');
            $table->bigInteger('applied_minor');
            $table->bigInteger('before_minor');
            $table->bigInteger('after_minor');
            $table->char('allocation_hash', 64);
            $table->date('effective_on');
            $table->text('evidence');
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported release journal engine.');
        }
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Held release journal is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held release journal is immutable'";
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'release_journal_reject_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON held_cash_release_journal {$body}");
        }
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER release_journal_reject_replace BEFORE INSERT ON held_cash_release_journal
                FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.id
                OR j.operation_uuid = NEW.operation_uuid OR j.proposal_uuid = NEW.proposal_uuid) {$body}");
        }
    }

    public function down(): void
    {
        throw new \LogicException('Release journal requires independently reviewed recovery.');
    }
};
