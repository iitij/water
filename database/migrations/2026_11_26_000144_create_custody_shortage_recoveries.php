<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_shortage_recoveries', function (Blueprint $table) {
            $table->id();
            // Initial bounded path: one full recovery per session. Partial
            // and mixed settlements require a separate reviewed extension.
            $table->foreignId('cash_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('treasury_posting_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('count_approval_id')->constrained('audit_events')->restrictOnDelete();
            $table->foreignId('proposal_id')->unique()->constrained('audit_events')->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('effective_on');
            $table->string('reference', 150)->unique();
            $table->uuid('operation_uuid')->unique();
            $table->text('evidence');
            $table->timestamps();
        });
        // No source updates/deletes. In SQLite a REPLACE can otherwise
        // suppress DELETE triggers; protect every declared unique identity.
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER fin_shortage_recovery_'.strtolower($event)
                    .' BEFORE '.$event.' ON custody_shortage_recoveries FOR EACH ROW BEGIN SELECT RAISE(ABORT, '
                    ."'Custody shortage recovery is immutable'); END");
            }
            DB::unprepared("CREATE TRIGGER fin_shortage_recovery_replace BEFORE INSERT ON custody_shortage_recoveries FOR EACH ROW
                WHEN EXISTS (SELECT 1 FROM custody_shortage_recoveries r WHERE r.id = NEW.id
                    OR r.cash_session_id = NEW.cash_session_id OR r.treasury_posting_id = NEW.treasury_posting_id
                    OR r.proposal_id = NEW.proposal_id OR r.reference = NEW.reference OR r.operation_uuid = NEW.operation_uuid)
                BEGIN SELECT RAISE(ABORT, 'Custody shortage recovery is immutable'); END");
        } else {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER fin_shortage_recovery_'.strtolower($event)
                    .' BEFORE '.$event." ON custody_shortage_recoveries FOR EACH ROW SIGNAL SQLSTATE '45000'"
                    ." SET MESSAGE_TEXT = 'Custody shortage recovery is immutable'");
            }
        }
    }

    public function down(): void
    {
        throw new \LogicException('Custody shortage recovery evidence requires reviewed recovery.');
    }
};
