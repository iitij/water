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
     * subscriber. A later migration replaces it with strict physical uniqueness.
     */
    public function up(): void
    {
        // Compatibility for databases that have not yet run this older
        // migration. The owner's newer policy explicitly authorizes permanent
        // removal of duplicate physical meters and their linked test data.
        // Keep the highest-ID current meter, then let the newer migration
        // remove every remaining historical/soft-deleted meter as well.
        DB::transaction(function (): void {
            $subscribers = DB::table('meters')
                ->select('subscriber_id')
                ->whereNull('removed_at')
                ->whereNull('deleted_at')
                ->groupBy('subscriber_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('subscriber_id');

            $purger = app(\\App\\Services\\MeterDataPurger::class);
            foreach ($subscribers as $subscriberId) {
                $ids = DB::table('meters')
                    ->where('subscriber_id', $subscriberId)
                    ->whereNull('removed_at')
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->pluck('id');
                foreach ($ids->skip(1) as $obsoleteId) {
                    $purger->purge((int) $obsoleteId);
                }
            }
        });

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
