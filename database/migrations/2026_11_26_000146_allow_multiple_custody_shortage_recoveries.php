<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the existing source rows, proposal identities and posting
        // links. Only the per-session cardinality changes.
        Schema::table('custody_shortage_recoveries', function (Blueprint $table) {
            $table->index(['cash_session_id', 'effective_on'], 'custody_recovery_session_date');
        });
        Schema::table('custody_shortage_recoveries', function (Blueprint $table) {
            $table->dropUnique(['cash_session_id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER fin_shortage_recovery_replace');
            DB::unprepared("CREATE TRIGGER fin_shortage_recovery_replace BEFORE INSERT ON custody_shortage_recoveries FOR EACH ROW
                WHEN EXISTS (SELECT 1 FROM custody_shortage_recoveries r WHERE r.id = NEW.id
                    OR r.treasury_posting_id = NEW.treasury_posting_id
                    OR r.proposal_id = NEW.proposal_id OR r.reference = NEW.reference OR r.operation_uuid = NEW.operation_uuid)
                BEGIN SELECT RAISE(ABORT, 'Custody shortage recovery is immutable'); END");
        }
    }

    public function down(): void
    {
        throw new \LogicException('Multiple recovery evidence cannot be collapsed into a single document.');
    }
};
