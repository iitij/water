<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A "current" meter has not been removed or soft-deleted.
     *
     * MySQL, MariaDB and SQLite allow multiple NULL values in a UNIQUE index.
     * A generated nullable column therefore enforces one current meter per
     * subscriber, while allowing any number of historical meters.
     */
    public function up(): void
    {
        // Do not silently select the first meter, erase others, or alter money.
        $conflicts = DB::table('meters')
            ->select('subscriber_id')
            ->whereNull('removed_at')
            ->whereNull('deleted_at')
            ->groupBy('subscriber_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->pluck('subscriber_id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce the single-current-meter constraint: subscribers '
                .$conflicts->implode(', ')
                .' have more than one meter without removed_at. '
                .'Review their readings, invoices and balances, then close the '
                .'obsolete meter associations with a documented removal date. '
                .'No meter records were changed by this migration.'
            );
        }

        Schema::table('meters', function (Blueprint $table) {
            $table->unsignedBigInteger('current_subscriber_id')
                ->nullable()
                ->virtualAs(
                    'CASE WHEN removed_at IS NULL AND deleted_at IS NULL '
                    .'THEN subscriber_id ELSE NULL END'
                );
            $table->unique('current_subscriber_id', 'meters_one_current_per_subscriber');
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->dropUnique('meters_one_current_per_subscriber');
            $table->dropColumn('current_subscriber_id');
        });
    }
};
