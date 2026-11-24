<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->string('period_basis', 32)->default('observation')->change();
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // A row CHECK needs no SUPER privilege or binary logging change.
            // Calendar-era rows are read-only in the application; the exception
            // exists solely for records present before this migration.
            DB::statement("ALTER TABLE readings ADD CONSTRAINT readings_observation_valid CHECK (
                period_basis IN ('observation', 'legacy_calendar') AND (
                    period_basis = 'legacy_calendar' OR (
                        actual_reading_date IS NOT NULL AND period_start IS NOT NULL AND period_end IS NOT NULL
                        AND period_start < period_end AND period_end = actual_reading_date
                        AND previous_reading >= 0 AND current_reading >= previous_reading
                        AND consumption_units = current_reading - previous_reading
                    )
                )
            )");

            return;
        }
        if ($driver !== 'sqlite') {
            throw new \RuntimeException('Unsupported database for observation invariants.');
        }
        $invalid = "NEW.period_basis NOT IN ('observation', 'legacy_calendar')
            OR (NEW.period_basis = 'observation' AND (
                NEW.actual_reading_date IS NULL OR NEW.period_start IS NULL OR NEW.period_end IS NULL
                OR DATE(NEW.period_start) IS NULL OR DATE(NEW.period_end) IS NULL OR DATE(NEW.actual_reading_date) IS NULL
                OR DATE(NEW.period_start) >= DATE(NEW.period_end)
                OR DATE(NEW.period_end) <> DATE(NEW.actual_reading_date)
                OR NEW.previous_reading < 0 OR NEW.current_reading < NEW.previous_reading
                OR ABS(NEW.previous_reading - ROUND(NEW.previous_reading, 2)) > 0.00001
                OR ABS(NEW.current_reading - ROUND(NEW.current_reading, 2)) > 0.00001
                OR ABS(NEW.consumption_units - (NEW.current_reading - NEW.previous_reading)) > 0.00001
            ))";
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'readings_observation_'.strtolower($event);
            $predicate = $event === 'UPDATE' ? "({$invalid}) OR OLD.period_basis <> NEW.period_basis" : $invalid;
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON readings
                FOR EACH ROW WHEN {$predicate}
                BEGIN SELECT RAISE(ABORT, 'readings_observation_valid'); END");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS readings_observation_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS readings_observation_update');
        } elseif ($driver === 'mariadb') {
            DB::statement('ALTER TABLE readings DROP CONSTRAINT readings_observation_valid');
        } else {
            DB::statement('ALTER TABLE readings DROP CHECK readings_observation_valid');
        }
        Schema::table('readings', function (Blueprint $table) {
            $table->string('period_basis', 32)->default('legacy_calendar')->change();
        });
    }
};
