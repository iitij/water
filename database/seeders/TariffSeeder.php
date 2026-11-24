<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tariff;

class TariffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data is a draft, never an automatically approved price.
        Tariff::firstOrCreate(['name' => 'تعرفة تجريبية غير معتمدة'], [
            'unit_price' => 600.00,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'is_active' => false,
        ]);
    }
}
