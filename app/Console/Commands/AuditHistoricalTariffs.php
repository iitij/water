<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only readiness audit. Never approves prices or backfills issued invoices. */
final class AuditHistoricalTariffs extends Command
{
    protected $signature = 'water:audit-historical-tariffs';
    protected $description = 'فحص جاهزية التعرفات والقراءات وأدلة التسعير دون تعديل البيانات';

    public function handle(): int
    {
        if (! Schema::hasTable('invoice_tariff_lines')) {
            $this->error('شغّل الفحص على نسخة اختبار بعد تطبيق ترحيلات ACC-08.');

            return self::FAILURE;
        }

        $tariffs = DB::table('tariffs')->whereNull('deleted_at')->orderBy('id')->get();
        $approved = $tariffs->where('is_active', true)->values();
        $invalid = 0;
        $overlaps = 0;
        foreach ($tariffs as $tariff) {
            if ($tariff->start_date !== null && $tariff->end_date !== null
                && $tariff->start_date > $tariff->end_date) {
                $invalid++;
                $this->error("INVALID_TARIFF #{$tariff->id}");
            }
        }
        foreach ($approved as $i => $first) {
            foreach ($approved->slice($i + 1) as $second) {
                if (($first->end_date === null || $second->start_date === null || $first->end_date >= $second->start_date)
                    && ($second->end_date === null || $first->start_date === null || $second->end_date >= $first->start_date)) {
                    $overlaps++;
                    $this->error("OVERLAP #{$first->id} / #{$second->id}");
                }
            }
        }

        $blocked = 0;
        DB::table('readings')->whereNull('deleted_at')->where('is_invoiced', false)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('meters')
                ->whereColumn('meters.id', 'readings.meter_id')
                ->whereNull('meters.deleted_at')->whereNull('meters.removed_at'))
            ->chunkById(500, function ($readings) use ($approved, &$blocked): void {
                foreach ($readings as $reading) {
                    $start = $reading->period_start;
                    $end = $reading->period_end;
                    if (! $start || ! $end || $start >= $end) {
                        $blocked++;
                        $this->error("INVALID_PERIOD reading #{$reading->id}");
                        continue;
                    }
                    $lastDay = CarbonImmutable::parse($end)->subDay()->toDateString();
                    $matches = $approved->filter(fn ($tariff) =>
                        ($tariff->start_date === null || $tariff->start_date <= $lastDay)
                        && ($tariff->end_date === null || $tariff->end_date >= $start));
                    $match = $matches->first();
                    if ($matches->count() !== 1
                        || ($match->start_date !== null && $match->start_date > $start)
                        || ($match->end_date !== null && $match->end_date < $lastDay)) {
                        $blocked++;
                        $this->error("UNPRICEABLE reading #{$reading->id}");
                    }
                }
            });

        $totals = DB::table('invoice_tariff_lines')->select('invoice_id')
            ->selectRaw('SUM(amount) AS priced_total')->groupBy('invoice_id');
        $mismatches = DB::table('invoices')->joinSub($totals, 'pricing', fn ($join) =>
            $join->on('invoices.id', '=', 'pricing.invoice_id'))
            ->whereRaw('ABS(pricing.priced_total - invoices.consumption_fee) > 0.005')
            ->count();
        $legacy = DB::table('invoices')->whereNull('deleted_at')->where('invoice_type', 'meter')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('invoice_tariff_lines')
                ->whereColumn('invoice_tariff_lines.invoice_id', 'invoices.id'))->count();

        $this->table(['check', 'count'], [
            ['invalid_tariffs', $invalid], ['overlapping_approved_pairs', $overlaps],
            ['unpriceable_unbilled_readings', $blocked], ['snapshot_total_mismatches', $mismatches],
            ['legacy_invoices_without_snapshot (informational)', $legacy],
        ]);

        if ($legacy > 0) {
            $this->warn('الفواتير القديمة بلا أدلة محفوظة لا تُعدّل أو تُعاد تسعيرها تلقائياً.');
        }

        return ($invalid + $overlaps + $blocked + $mismatches) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
