<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Financial fixtures use the same actual observation algorithm as production. */
    protected function createMeasuredReading(array $data): \App\Models\Reading
    {
        $meter = \App\Models\Meter::findOrFail($data['meter_id']);
        if (! $meter->getLatestReading() && ! $meter->installed_at && ! $meter->subscriber->subscription_start_date) {
            $meter->update(['installed_at' => $data['period_start']]);
        }
        $data['actual_reading_date'] = $data['period_end'];
        unset($data['period_start'], $data['period_end']);

        return \App\Models\Reading::create($data);
    }

    /** Only tests specifically simulating pre-upgrade records may bypass new writes. */
    protected function insertLegacyReading(array $data): \App\Models\Reading
    {
        $data['period_basis'] = 'legacy_calendar';
        $data['consumption_units'] = round((float) $data['current_reading'] - (float) $data['previous_reading'], 2);
        $data['created_at'] = now();
        $data['updated_at'] = now();

        return \App\Models\Reading::findOrFail(\Illuminate\Support\Facades\DB::table('readings')->insertGetId($data));
    }

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite') && extension_loaded('pdo_mysql')) {
            $_ENV['DB_CONNECTION'] = 'mysql';
            $_ENV['DB_DATABASE'] = 'water_test';
            $_SERVER['DB_CONNECTION'] = 'mysql';
            $_SERVER['DB_DATABASE'] = 'water_test';
            putenv('DB_CONNECTION=mysql');
            putenv('DB_DATABASE=water_test');
        }

        parent::setUp();
    }
}
