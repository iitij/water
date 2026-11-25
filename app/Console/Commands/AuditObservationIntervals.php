<?php

namespace App\Console\Commands;

use App\Support\ReadingQuantity;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditObservationIntervals extends Command
{
    protected $signature = 'water:audit-observation-intervals';
    protected $description = 'تدقيق فترات القراءات التاريخية وتسلسل القراءات الفعلية دون تعديل البيانات';

    public function handle(): int
    {
        if (! Schema::hasColumn('readings', 'period_basis')) {
            $this->error('Apply the observation migrations to an isolated database copy first.');
            return self::FAILURE;
        }

        $legacy = 0;
        $blocked = 0;
        $date = static fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString();
        DB::table('readings')->whereNull('deleted_at')->chunkById(500, function ($rows) use (&$legacy, &$blocked, $date): void {
            foreach ($rows as $row) {
                $start = $date($row->period_start);
                $end = $date($row->period_end);
                $observed = $date($row->actual_reading_date ?? $row->reading_date);
                if ($row->period_basis === 'legacy_calendar') {
                    if ($start && $observed && $observed < $start) {
                        $legacy++;
                        $this->warn("LEGACY_EARLY_OBSERVATION reading #{$row->id} invoice #".($row->invoice_id ?? '-'));
                    }
                    continue;
                }
                if ($row->period_basis !== 'observation' || ! $row->actual_reading_date || ! $start || ! $end || ! $observed
                    || $start >= $end || $end !== $observed || $observed > today()->toDateString()) {
                    $blocked++;
                    $this->error("INVALID_OBSERVATION reading #{$row->id}");
                    continue;
                }
                try {
                    $previousQuantity = ReadingQuantity::hundredths($row->previous_reading);
                    $currentQuantity = ReadingQuantity::hundredths($row->current_reading);
                    $units = ReadingQuantity::hundredths($row->consumption_units);
                    if ($currentQuantity < $previousQuantity || $units !== $currentQuantity - $previousQuantity) {
                        throw new InvalidArgumentException('Inconsistent consumption.');
                    }
                } catch (InvalidArgumentException) {
                    $blocked++;
                    $this->error("INVALID_OBSERVATION_QUANTITY reading #{$row->id}");
                    continue;
                }
                // Retired meter records deliberately retain only historical financial provenance.
                if (! $row->meter_id) {
                    continue;
                }
                // A strict predecessor query alone misses two observations on
                // the same day, both of which can appear to share a valid anchor.
                if (DB::table('readings')->where('meter_id', $row->meter_id)
                    ->whereNull('deleted_at')->where('id', '!=', $row->id)
                    ->whereRaw('DATE(COALESCE(actual_reading_date, reading_date)) = ?', [$observed])->exists()) {
                    $blocked++;
                    $this->error("DUPLICATE_OBSERVATION_DATE reading #{$row->id}");
                    continue;
                }
                $previous = DB::table('readings')->where('meter_id', $row->meter_id)
                    ->whereNull('deleted_at')->where('id', '!=', $row->id)
                    ->whereRaw('DATE(COALESCE(actual_reading_date, reading_date)) < ?', [$observed])
                    ->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')->orderByDesc('id')->first();
                if ($previous) {
                    $anchor = $date($previous->actual_reading_date ?? $previous->reading_date);
                    $value = $previous->current_reading;
                } else {
                    $meter = DB::table('meters')->where('id', $row->meter_id)->first();
                    $service = $meter ? DB::table('subscribers')->where('id', $meter->subscriber_id)->value('subscription_start_date') : null;
                    $anchor = $date($meter->installed_at ?? $service);
                    $value = $meter->initial_reading ?? null;
                }
                try {
                    $anchorMatches = $anchor === $start && ReadingQuantity::hundredths($value) === $previousQuantity;
                } catch (InvalidArgumentException) {
                    $anchorMatches = false;
                }
                if (! $anchorMatches) {
                    $blocked++;
                    $this->error("BROKEN_OBSERVATION_ANCHOR reading #{$row->id}");
                }
            }
        });
        $this->table(['check', 'count'], [
            ['legacy_early_observations (review required)', $legacy],
            ['invalid_observation_chains', $blocked],
        ]);
        if ($legacy) {
            $this->warn('Legacy records require documented review. No dates, invoices or ledger entries were changed.');
        }

        return $blocked > 0 ? self::FAILURE : self::SUCCESS;
    }
}
