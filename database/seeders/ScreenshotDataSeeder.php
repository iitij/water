<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ScreenshotDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SyntheticObservationSeeder::class);
    }
}
