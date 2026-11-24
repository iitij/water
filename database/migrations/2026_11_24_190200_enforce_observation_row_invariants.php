<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing rows keep their explicit marker. All normal new rows default
        // to observations; only deliberate archival imports can specify legacy.
        Schema::table('readings', function (Blueprint $table) {
            $table->string('period_basis', 32)->default('observation')->change();
        });

        $invalid = "NEW.period_basis NOT IN ('observation', 'legacy_calendar')
            OR (NEW.period_basis = 'observation' AND (
                NEW.actual_reading_date IS NULL OR NEW.period_start IS NULL OR NEW.period_end IS NULL
                OR DATE(NEW.period_start) >= DATE(NEW.period_end)
                OR DATE(NEW.period_end) <> DATE(NEW.actual_reading_date)
                OR NEW.previous_reading < 0 OR NEW.current_reading < NEW.previous_reading
                OR ABS(NEW.consumption_units - (NEW.current_reading - NEW.previous_reading)) > 0.00001
            ))";
        $driver = DB::connection()->getDriverName();
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'readings_observation_'.strtolower($event);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON readings
                    FOR EACH ROW WHEN {$invalid}
                    BEGIN SELECT RAISE(ABORT, 'Invalid observation interval or consumption'); END");
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON readings
                    FOR EACH ROW BEGIN IF {$invalid} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid observation interval or consumption';
                    END IF; END");
            } else {
                throw new \RuntimeException('Unsupported database for observation invariants.');
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS readings_observation_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS readings_observation_update');
        Schema::table('readings', function (Blueprint $table) {
            $table->string('period_basis', 32)->default('legacy_calendar')->change();
        });
    }
};
