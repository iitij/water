<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ScreenshotReadingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SyntheticObservationSeeder::class);
    }
}
