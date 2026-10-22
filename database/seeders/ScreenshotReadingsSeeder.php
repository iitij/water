<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScreenshotReadingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('readings')->truncate();

        $readings = [
            '2025063598' => 22,
            '2025060939' => 32,
            '2025061601' => 7,
            '2025062306' => 15,
            '2025060419' => 14,
            '2025061649' => 10,
            '2025561666' => 19,
            '2025063781' => 17,
            '2025060646' => 22,
            '2025060407' => 52,
            '2025061603' => 23,
            '2025063641' => 43,
            '2025062369' => 21,
            '2025060956' => 12,
            '2025062470' => 9,
            '2025062485' => 20,
            '2025063533' => 18,
            '2025062309' => 56,
            '2025062495' => 53,
            '2025062495-2' => 13, // Adjusted duplicate
            '2025061658' => 95,
            '2025060461' => 95,
            '2025062377' => 9,
            '2025063515' => 8,
            '2025061655' => 2,
            '2025061698' => 2,
            '202506' => 7,
            '2025062445' => 26,
            '2025060134' => 99,
            '2025063552' => 51,
            '20255060092' => 17,
            '2025061981' => 22,
            '2025060143' => 9,
            '2025061902' => 9,
            '2025061661' => 5,
            '2025060983' => 35,
            '2025063796' => 0,
            '2025063760' => 5,
            '2025061646' => 10,
            '2025060761' => 25,
            '2025067099' => 13,
            '2025067437' => 8,
            '2025060644' => 7,
        ];

        $meters = DB::table('meters')->get()->keyBy('unique_code');

        foreach ($readings as $code => $currentReading) {
            if (isset($meters[$code])) {
                DB::table('readings')->insert([
                    'meter_id' => $meters[$code]->id,
                    'reading_date' => '2026-05-31',
                    'actual_reading_date' => '2026-05-31',
                    'previous_reading' => 0,
                    'current_reading' => $currentReading,
                    'consumption_units' => $currentReading - 0,
                    'is_invoiced' => false,
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
