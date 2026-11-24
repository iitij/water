<?php

namespace Database\Seeders;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Optional demo fixtures; never truncate or import real customer records. */
final class SyntheticObservationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic observations are available only in local/testing environments.');
        }

        DB::transaction(function (): void {
            $square = Square::firstOrCreate(['name' => 'مربع تجريبي للقراءات']);
            $baseline = today()->subDays(60)->toDateString();
            $first = today()->subDays(30)->toDateString();
            $second = today()->subDay()->toDateString();

            foreach (range(1, 12) as $index) {
                $code = 'DEMO-OBS-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                if (Meter::withTrashed()->where('unique_code', $code)->exists()) {
                    continue;
                }

                $subscriber = Subscriber::create([
                    'name' => 'مشترك تجريبي '.$index,
                    'square_id' => $square->id,
                    'status' => 'active',
                    'subscription_start_date' => $baseline,
                ]);
                $meter = Meter::create([
                    'subscriber_id' => $subscriber->id,
                    'unique_code' => $code,
                    'initial_reading' => '100.25',
                    'installed_at' => $baseline,
                ]);
                Reading::create([
                    'meter_id' => $meter->id,
                    'reading_date' => $first,
                    'current_reading' => number_format(100.25 + $index * 2.5, 2, '.', ''),
                ]);
                Reading::create([
                    'meter_id' => $meter->id,
                    'reading_date' => $second,
                    'current_reading' => number_format(100.25 + $index * 4.75, 2, '.', ''),
                ]);
            }
        });
    }
}
