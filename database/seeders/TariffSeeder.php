<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TariffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('tariffs')->insert([
            'name' => 'التسعيرة الافتراضية',
            'unit_price' => 600.00,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
