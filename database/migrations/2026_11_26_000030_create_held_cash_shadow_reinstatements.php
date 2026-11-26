<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('held_cash_shadow_reinstatements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('held_cash_release_journal')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('payment_allocation_revisions')->restrictOnDelete();
            $table->char('allocation_hash', 64);
            $table->bigInteger('before_minor');
            $table->bigInteger('applied_minor');
            $table->bigInteger('after_minor');
            $table->unique(['journal_id', 'revision_id'], 'shadow_restore_anchor_revision_unique');
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \LogicException('Unsupported shadow reinstatement engine.');
        }
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Shadow reinstatement is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shadow reinstatement is immutable'";
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'shadow_restore_reject_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON held_cash_shadow_reinstatements {$body}");
        }
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER shadow_restore_reject_replace BEFORE INSERT ON held_cash_shadow_reinstatements
                FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements s WHERE s.id = NEW.id
                OR (s.journal_id = NEW.journal_id AND s.revision_id = NEW.revision_id)) {$body}");
        }
    }

    public function down(): void
    {
        throw new \LogicException('Shadow reinstatement history requires independently reviewed recovery.');
    }
};
