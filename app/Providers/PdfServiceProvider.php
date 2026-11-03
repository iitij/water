<?php

namespace App\Providers;

use App\Services\Pdf\SerialBrowsershotDriver;
use Illuminate\Support\ServiceProvider;

class PdfServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->singleton('laravel-pdf.driver.browsershot', fn () =>
            new SerialBrowsershotDriver(config('laravel-pdf.browsershot', [])));
    }
}
