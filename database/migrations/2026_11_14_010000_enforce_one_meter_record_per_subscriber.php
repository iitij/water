<?php

use App\Services\MeterDataPurger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Irreversible transition from "one CURRENT + many historical meters" to
 * "at most ONE physically stored meter per subscriber".
 *
 * For each subscriber,
 * keep the highest-ID non-removed, non-deleted meter; permanently erase every
 * other meter and financially unlinked operational readings. Financial conflicts
 * must block cleanup at this historical schema boundary. If no usable meter remains, keep NONE rather than falsely
 * reactivating a removed/deleted physical meter.
 *
 * down() deliberately cannot restore data purged by up().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $purger = app(MeterDataPurger::class);
            DB::table('meters')
                ->select('subscriber_id')
                ->distinct()
                ->orderBy('subscriber_id')
                ->pluck('subscriber_id')
                ->each(function ($subscriberId) use ($purger): void {
                    $meters = DB::table('meters')
                        ->where('subscriber_id', $subscriberId)
                        ->orderByDesc('id')
                        ->get(['id', 'removed_at', 'deleted_at']);
                    $survivor = $meters->first(fn ($meter) =>
                        $meter->removed_at === null && $meter->deleted_at === null
                    );

                    foreach ($meters as $meter) {
                        if ($survivor !== null && $meter->id === $survivor->id) {
                            continue;
                        }
                        $purger->purgeLegacyObsolete((int) $meter->id, $survivor ? (int) $survivor->id : null);
                    }
                });

            $conflict = DB::table('meters')
                ->select('subscriber_id')
                ->groupBy('subscriber_id')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($conflict !== null ||
                DB::table('meters')->whereNotNull('removed_at')->exists() ||
                DB::table('meters')->whereNotNull('deleted_at')->exists()) {
                throw new RuntimeException('Single-meter cleanup did not remove every obsolete record.');
            }
        });

        // The old nullable generated key only constrained CURRENT meters.
        // Enforce physical uniqueness for all records, including direct SQL.
        Schema::table('meters', function (Blueprint $table): void {
            $table->dropUnique('meters_one_current_per_subscriber');
            $table->dropColumn('current_subscriber_id');
        });
        Schema::table('meters', function (Blueprint $table): void {
            $table->unique('subscriber_id', 'meters_one_per_subscriber');
        });
    }

    public function down(): void
    {
        // Never silently remove the final uniqueness guarantee: purged meter
        // rows and associated finance cannot be restored by a rollback.
        throw new RuntimeException(
            'This destructive one-meter migration is irreversible. '
            .'Restore a verified test database snapshot instead of rollback.'
        );
    }
};
