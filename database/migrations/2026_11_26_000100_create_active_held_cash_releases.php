<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_cash_active_releases', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->primary();
            $table->foreign('journal_id')->references('id')->on('held_cash_release_journal')->restrictOnDelete();
            $table->foreignId('credit_id')->unique()->constrained('treasury_held_cash_credits')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported active held release driver.');
        }
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Active held release is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active held release is immutable'";
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared('CREATE TRIGGER active_held_release_reject_'.strtolower($event)
                ." BEFORE {$event} ON held_cash_active_releases {$body}");
        }
        if ($sqlite) DB::unprepared("CREATE TRIGGER active_held_release_reject_replace BEFORE INSERT ON held_cash_active_releases
            FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_active_releases a
            WHERE a.journal_id = NEW.journal_id OR a.credit_id = NEW.credit_id) {$body}");
    }

    public function down(): void
    {
        throw new \LogicException('Active held release requires independently reviewed recovery.');
    }
};
