<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
